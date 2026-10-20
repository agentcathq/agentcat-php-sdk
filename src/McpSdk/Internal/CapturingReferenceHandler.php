<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\ThrowableStash;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;

/**
 * The inner tap: runs every tool, prompt and resource through the reference handler the
 * Builder would use, and stashes a tool's exception for the request it ran for before mcp/sdk catches it. It
 * changes nothing else: the arguments and the result pass unchanged, and the exception is rethrown.
 *
 * @internal
 */
final class CapturingReferenceHandler implements ReferenceHandlerInterface
{
    private ?ReferenceHandlerInterface $resolved = null;

    /**
     * @param \Closure(): ReferenceHandlerInterface $inner the handler to run, resolved once at the first call
     */
    public function __construct(
        private readonly \Closure $inner,
        private readonly ThrowableStash $stash,
    ) {}

    /** @param array<string, mixed> $arguments */
    #[\Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        $inner = $this->resolved ??= ($this->inner)();
        try {
            return $inner->handle($reference, $arguments);
        } catch (\Throwable $e) {
            $request = $arguments['_request'] ?? null; // CallToolHandler passes the request it was given: AgentCat's
            if ($reference instanceof ToolReference && is_object($request)) {
                $this->stash->put($request, $e);
            }

            throw $e;
        }
    }
}
