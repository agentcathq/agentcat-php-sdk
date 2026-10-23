<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Access\CallToolHandlerAccess;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * The handlers that answer after AgentCat's: the library's own, or a customer's, added before or after track(). They
 * come from the request-handler list build() assembled, in which AgentCat's handlers come first. The list is read at
 * the first request, when the server is built, and kept. One instance serves every AgentCat handler of a Builder.
 *
 * @internal
 */
final class Delegates
{
    /** @var list<RequestHandlerInterface<mixed>>|null */
    private ?array $handlers = null;

    /** @var \WeakMap<CallToolHandler, RequestHandlerInterface<mixed>> the library's tools/call handler => the one that runs the tools */
    private \WeakMap $runners;

    public function __construct(private readonly Builder $builder)
    {
        $this->runners = new \WeakMap();
    }

    /**
     * The handler that runs a `tools/call` after `$self`: `after()`'s, except that the library's own handler is
     * replaced, once, by a copy whose reference handler stashes a failing tool's exception. The copy runs the tool
     * through the reference handler that build() gave the library's handler, so a reference handler or a container
     * set before or after track() is the one that runs. When the copy cannot be made (`Drift`), the library's own
     * handler runs the tools, and the events have no exception.
     *
     * @return RequestHandlerInterface<mixed>|null
     */
    public function runner(object $self, Request $request, ThrowableStash $stash): ?RequestHandlerInterface
    {
        $handler = $this->after($self, $request);
        if (!$handler instanceof CallToolHandler) {
            return $handler; // a customer's handler, or none
        }
        if (!isset($this->runners[$handler])) {
            try {
                $this->runners[$handler] = StrictErrors::run(static fn(): CallToolHandler => new CallToolHandler(
                    CallToolHandlerAccess::registry($handler),
                    new CapturingReferenceHandler(CallToolHandlerAccess::referenceHandler($handler), $stash),
                    CallToolHandlerAccess::logger($handler),
                    CallToolHandlerAccess::schemaValidator($handler),
                ));
            } catch (\Throwable) {
                Drift::report($this->builder);
                $this->runners[$handler] = $handler;
            }
        }

        return $this->runners[$handler];
    }

    /**
     * The first handler after `$self` that supports the request. mcp/sdk always has one of its own, so null means
     * that its internals changed: the list cannot be read, `$self` is not in it, or nothing after `$self`
     * answers. That drift is logged once per Builder, and AgentCat's handlers step aside.
     *
     * @return RequestHandlerInterface<mixed>|null
     */
    public function after(object $self, Request $request): ?RequestHandlerInterface
    {
        $seen = false;
        foreach ($this->handlers() as $handler) {
            if ($handler === $self) {
                $seen = true;
            } elseif ($seen && $handler->supports($request)) {
                return $handler; // the library's own handler, or a customer's
            }
        }
        Drift::report($this->builder);

        return null;
    }

    /** The library's own answer when no handler supports a request. */
    public static function notFound(Request $request): Error
    {
        return Error::forMethodNotFound(sprintf('No handler found for method "%s".', $request::getMethod()), $request->getId());
    }

    /** @return list<RequestHandlerInterface<mixed>> */
    private function handlers(): array
    {
        $this->handlers ??= $this->read();

        return $this->handlers ?? [];
    }

    /** @return list<RequestHandlerInterface<mixed>>|null the handlers in the built list, or null when it cannot be read */
    private function read(): ?array
    {
        try {
            $entries = StrictErrors::run(fn(): ?array => BuilderAccess::requestHandlers($this->builder));
        } catch (\Throwable) {
            return null; // a missing or retyped member
        }
        if ($entries === null) {
            return null;
        }
        $handlers = [];
        foreach ($entries as $entry) {
            if ($entry instanceof RequestHandlerInterface) {
                $handlers[] = $entry;
            }
        }

        return $handlers;
    }
}
