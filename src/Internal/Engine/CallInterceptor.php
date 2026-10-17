<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Errors\ExceptionCapture;
use AgentCat\Internal\Handles\ArgumentStripper;
use AgentCat\Internal\Handles\HandleResolution;
use AgentCat\Internal\Handles\HandleResolver;
use AgentCat\Internal\Handles\HandleTags;
use AgentCat\Internal\Handles\MintBack;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Tokens\TokenEstimator;
use AgentCat\Internal\Tools\GetMoreTools;

/**
 * Port of the TypeScript tools/call wrapper (callWrap.ts, docs/audit/02-engine.md §2.8). One event per traced call;
 * customer hooks never run here (D04). Rethrows the library's exceptions unchanged and adds none of its own (D32).
 *
 * @internal
 */
final readonly class CallInterceptor
{
    private const string POST_HANDLER_FAILED = "Warning: AgentCat post-handler processing failed for tool %s; returning the customer's result untouched - %s";

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private Publisher $publisher, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * `TSeam` lets PHPStan infer `TResult` from the seam's declared ancestor instead of comparing the two (PHPStan
     * 2.3 does not match an invariant `CallSeam<Response<mixed>|…>` against itself).
     *
     * @template TResult
     * @template TSeam of CallSeam<TResult>
     *
     * @param TSeam $seam
     *
     * @return TResult
     */
    public function intercept(TrackedServer $server, CallSeam $seam): mixed
    {
        $options = $server->options;
        $name = $seam->toolName();
        $intercept = $options->enableReportMissing && $name === GetMoreTools::NAME;

        if (!$options->enableTracing) {
            return $this->untraced($intercept, $seam); // D15 (B06): with tracing off the request goes on unstripped
        }

        $start = ($this->clock)();
        try {
            ToolListing::ensureRegistries($server, $seam->listOriginal(...));
            $arguments = $seam->arguments();
            $resolution = HandleResolver::resolve($arguments, $server->hookMode(), $options->enableAgentTracking, !$server->injection->hasDeclaredSessionParam($name));
            $call = $this->start($server, $name, $arguments, $resolution, $seam->describe(), $start, $intercept);
        } catch (\Throwable $e) {
            Log::write(sprintf('Warning: AgentCat tracing failed for tool %s, falling back to original handler - %s', $name, ErrorText::of($e)));

            return $this->untraced($intercept, $seam);
        }

        try {
            if ($intercept) {
                $tree = GetMoreTools::result($arguments);

                return $this->finish($call, $seam, $seam->answer($tree), new Observed($tree));
            }
            $result = $seam->callStripped(ArgumentStripper::names($name, $server->registry));
        } catch (\Throwable $e) {
            try {
                $this->publishFailure($call, $e);
            } catch (\Throwable $failure) {
                // D32: recording the library's exception must never replace it.
                Log::write(sprintf(self::POST_HANDLER_FAILED, $name, ErrorText::of($failure)));
            }

            throw $e;
        }

        return $this->finish($call, $seam, $result, null);
    }

    /**
     * The tracing-off and degraded paths: no event, no strip, no mint-back.
     *
     * @template TResult
     *
     * @param CallSeam<TResult> $seam
     *
     * @return TResult
     */
    private function untraced(bool $intercept, CallSeam $seam): mixed
    {
        return $intercept ? $seam->answer(GetMoreTools::result($seam->arguments())) : $seam->callOriginal();
    }

    /** Builds the event and the deferred hooks; runs no customer code. */
    private function start(
        TrackedServer $server,
        string $name,
        mixed $arguments,
        HandleResolution $resolution,
        CallContext $context,
        \DateTimeImmutable $start,
        bool $intercept,
    ): TracedCall {
        $event = new Event(
            sessionId: $resolution->sessionId,
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: $start,
            // Header conflict 4: mcp/sdk cannot tell empty arguments from absent ones.
            inputTokens: $arguments instanceof \stdClass && get_object_vars($arguments) === [] ? null : TokenEstimator::input($arguments),
            resourceName: $name === '' ? 'Unknown Tool' : $name,
            parameters: (object) ['request' => $context->request, 'extra' => $context->extra],
        );
        $mrtr = $context->continuation ? [Constants::TAG_MRTR => 'continuation'] : [];
        $event->tags = [...$mrtr, ...HandleTags::build($resolution, $context->protocolVersion)];
        // get_more_tools keeps its intent with enableToolCallContext off; with report-missing off it has none.
        $intent = $intercept || ($server->options->enableToolCallContext && $name !== GetMoreTools::NAME)
            ? HandleTags::userIntent($arguments, $name, true)
            : null;
        $invalidIntent = null;
        if (is_string($intent)) {
            $event->userIntent = $intent;
        } elseif ($intent !== null && !is_array($intent)) {
            $invalidIntent = get_debug_type($intent);
        }

        return new TracedCall($server, $event, $resolution, $context, PendingHooks::build($server, $context->toolCall), $start, $invalidIntent);
    }

    /**
     * TS finish(): decorate, record, publish. Never throws: on failure the customer's result goes back untouched.
     *
     * @template TResult
     *
     * @param CallSeam<TResult> $seam
     * @param TResult $result
     *
     * @return TResult
     */
    private function finish(TracedCall $call, CallSeam $seam, mixed $result, ?Observed $observed): mixed
    {
        try {
            $observed ??= $seam->observe($result);
            if ($observed->protocolError !== null) {
                // A JSON-RPC error answer: the TS catch path, no mint-back (header conflict 3).
                $this->publishFailure($call, $observed->thrown ?? $observed->protocolError);

                return $result;
            }
            $event = $call->event;
            $tree = $observed->tree;
            $final = $result;
            if ($tree instanceof \stdClass && ($tree->resultType ?? null) === 'input_required') {
                $tags = $event->tags ?? [];
                $tags[Constants::TAG_MRTR] = 'input_required';
                $event->tags = $tags;
            } else {
                $decorated = MintBack::decorate($tree, $call->resolution, $call->server->mirrorAllowed($seam->toolName()));
                if ($decorated !== $tree && $decorated instanceof \stdClass) {
                    $final = $seam->replace($result, $decorated);
                }
            }
            if ($tree instanceof \stdClass && ($tree->isError ?? null) === true) {
                $event->isError = true;
                $event->error = ExceptionCapture::capture($observed->thrown ?? $tree);
            }
            $event->response = $tree; // the mint-back is wire-only
            $event->outputTokens = TokenEstimator::output($tree);
            $event->duration = Publisher::elapsedMs($call->start, ($this->clock)());
            $this->publish($call);

            return $final;
        } catch (\Throwable $e) {
            Log::write(sprintf(self::POST_HANDLER_FAILED, $seam->toolName(), ErrorText::of($e)));

            return $result;
        }
    }

    /** The TS catch path: an error event for a library exception or a JSON-RPC error answer. */
    private function publishFailure(TracedCall $call, mixed $error): void
    {
        $event = $call->event;
        $event->isError = true;
        $event->error = ExceptionCapture::capture($error);
        $event->duration = Publisher::elapsedMs($call->start, ($this->clock)());
        $this->publish($call);
    }

    private function publish(TracedCall $call): void
    {
        if ($call->invalidIntent !== null) {
            // B16: a number, a boolean or an object as the intent. TypeScript loses this event at truncation, after
            // its hooks ran; here they never run (N10). A list intent is published with no intent (PHP divergence:
            // TypeScript sends the raw list).
            Log::write(sprintf('Failed to truncate event: UnexpectedValueException: userIntent is not a string (%s)', $call->invalidIntent));

            return;
        }
        $this->publisher->publishToolCall($call->server, $call->event, $call->context->clientName, $call->context->clientVersion, $call->pending);
    }
}
