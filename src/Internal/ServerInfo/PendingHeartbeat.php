<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Delivery\DeferredWork;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\ApiBaseUrl;

/**
 * One due heartbeat, from the check that started it until the queue runs it, in the window after the response of the
 * same request: the tracked server and the lists of the request. The run reads the lists, redacts their text with the
 * customer's `redactSensitiveInformation` function, and queues the request (`Sender`).
 *
 * Whatever fails, it sends nothing and logs one line, and nothing of the customer's code leaves it: no error, no PHP
 * warning or notice (an error handler takes them), no output (`OutputGuard`). A log line names the class of what was
 * thrown, never its message, because a message can hold what the redaction function is there to hide. When the build
 * has ended, it has let go of the lists of the request, and so of the session or the context that they hold; what it
 * hands back for a request on its way (`SentHeartbeat`) holds nothing of the request.
 *
 * @internal
 */
final class PendingHeartbeat implements DeferredWork
{
    /** Reading the lists and redacting them stop after this many seconds. */
    public const float BUILD_SECONDS = 30.0;

    private const string SKIPPED = 'Skipped the server info heartbeat: ';

    /** The lists of the request; null once the run took them, so nothing of the request outlives the build. */
    private ?ServerInfoSource $source;

    /** @param \Closure(): float $clock seconds on a monotonic clock */
    public function __construct(
        private readonly TrackedServer $server,
        ServerInfoSource $source,
        private readonly \Closure $clock,
    ) {
        $this->source = $source;
    }

    #[\Override]
    public function run(float $secondsLeft): ?SentHeartbeat
    {
        $source = $this->source;
        $this->source = null;
        if (!$source instanceof ServerInfoSource) {
            return null; // it ran already
        }
        set_error_handler(static fn(): bool => true);
        $output = OutputGuard::start();

        try {
            $body = $this->build($source, $secondsLeft);
        } catch (Skipped $skipped) {
            $body = null;
            Log::write(self::SKIPPED . $skipped->getMessage());
        } catch (\Throwable $e) {
            $body = null;
            Log::write(self::SKIPPED . ErrorName::of($e));
        }
        unset($source);
        $output->end(); // what the customer's code printed, and every buffer it left open
        restore_error_handler(); // every throwable is caught above, and the log never throws

        return $body instanceof ServerInfoBody ? $this->send($body) : null;
    }

    #[\Override]
    public function drop(string $reason): void
    {
        $this->source = null;
        Log::write(self::SKIPPED . $reason);
    }

    /**
     * The body, read and redacted: every call into the customer's code is here.
     *
     * @throws \Throwable
     */
    private function build(ServerInfoSource $source, float $secondsLeft): ServerInfoBody
    {
        [$name, $version] = $source->server();
        if ($name === null || $name === '') {
            throw new Skipped('the server name is unknown');
        }
        $clock = $this->clock;
        $started = $clock();
        $check = static function () use ($clock, $started, $secondsLeft): void {
            $taken = $clock() - $started;
            if ($taken >= self::BUILD_SECONDS) {
                throw new Skipped(sprintf('reading and redacting the lists took more than %s s', self::BUILD_SECONDS));
            }
            if ($taken >= $secondsLeft) {
                throw new Skipped('the delivery time ended before the lists were read');
            }
        };
        $body = BodyBuilder::build($this->server->projectId, $name, $version, $source, $this->server->injectionConfig(), $clock, $check);
        $redactor = $this->server->options->redactSensitiveInformation;
        if ($redactor instanceof \Closure) {
            try {
                $body = TextRedaction::apply($body, $redactor, $check);
            } catch (Skipped $skipped) {
                throw $skipped;
            } catch (\Throwable $e) {
                throw Skipped::after('redactSensitiveInformation failed - ' . ErrorName::of($e), $e);
            }
        }
        $check();

        return $body;
    }

    private function send(ServerInfoBody $body): ?SentHeartbeat
    {
        try {
            $queue = $this->server->publisher()->queue();

            return Sender::send($queue->channel(), ApiBaseUrl::serverInfoEndpoint($queue->baseUrl()), $body);
        } catch (\Throwable $e) {
            Log::write(self::SKIPPED . ErrorName::of($e));

            return null;
        }
    }
}
