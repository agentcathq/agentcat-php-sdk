<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Support\StrictErrors;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * The handlers that answer after AgentCat's: the library's own, or a customer's added after track(). They come
 * from the request-handler list build() assembled, in which AgentCat's handlers come first. The list is read at the
 * first request, when the server is built, and kept. One instance serves every AgentCat handler of a Builder.
 *
 * @internal
 */
final class Delegates
{
    /** @var list<RequestHandlerInterface<mixed>>|null */
    private ?array $handlers = null;

    public function __construct(private readonly Builder $builder) {}

    /**
     * The first handler after `$self` that supports the request. mcp/sdk always has one of its own, so null means
     * that its internals changed (C6): the list cannot be read, `$self` is not in it, or nothing after `$self`
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
                return $handler; // the library's own handler, or a customer handler added after track()
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
