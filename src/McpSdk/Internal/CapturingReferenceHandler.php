<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\ThrowableStash;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;

/**
 * The inner tap: runs a tool through the reference handler of the library's `tools/call` handler, and stashes the
 * tool's exception for the request it ran for before mcp/sdk catches it. It changes nothing else: the arguments and the
 * result pass unchanged, and the exception is rethrown.
 *
 * @internal
 */
final readonly class CapturingReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private ThrowableStash $stash,
    ) {}

    /** @param array<string, mixed> $arguments */
    #[\Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->inner->handle($reference, $arguments);
        } catch (\Throwable $e) {
            $request = $arguments['_request'] ?? null; // CallToolHandler passes the request it was given: AgentCat's
            if ($reference instanceof ToolReference && is_object($request)) {
                $this->stash->put($request, $e);
            }

            throw $e;
        }
    }
}
