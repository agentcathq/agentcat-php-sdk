<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\McpSdkAdapter;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * The handlers that answer after AgentCat's: the library's own, or a customer's added after track(). They come
 * from the request-handler list build() assembled, in which AgentCat's handlers come first. The list is read at the
 * first request, when the server is built, and kept.
 *
 * @internal
 */
final class Delegates
{
    /** @var list<RequestHandlerInterface<mixed>>|null */
    private ?array $handlers = null;

    private bool $drifted = false;

    public function __construct(private readonly Builder $builder) {}

    /**
     * False when mcp/sdk's built handler list cannot be read (C6: `probe()` runs before build(), so it cannot check
     * the built parts). AgentCat's handlers then step aside and the library's own answer.
     */
    public function available(): bool
    {
        return $this->handlers() !== null;
    }

    /**
     * The first handler after `$self` that supports the request, or null when there is none.
     *
     * @return RequestHandlerInterface<mixed>|null
     */
    public function after(object $self, Request $request): ?RequestHandlerInterface
    {
        $seen = false;
        foreach ($this->handlers() ?? [] as $handler) {
            if ($handler === $self) {
                $seen = true;
            } elseif ($seen && $handler->supports($request)) {
                return $handler; // the library's own handler, or a customer handler added after track()
            }
        }

        return null;
    }

    /** The library's own answer when no handler supports a request. */
    public static function notFound(Request $request): Error
    {
        return Error::forMethodNotFound(sprintf('No handler found for method "%s".', $request::getMethod()), $request->getId());
    }

    /**
     * Reads the list once. A missing or retyped member, or no list at request time, logs one compatibility line.
     *
     * @return list<RequestHandlerInterface<mixed>>|null
     */
    private function handlers(): ?array
    {
        if ($this->handlers === null && !$this->drifted) {
            $this->handlers = $this->read();
            $this->drifted = $this->handlers === null;
            if ($this->drifted) {
                Log::write(McpSdkAdapter::COMPATIBILITY_LINE);
            }
        }

        return $this->handlers;
    }

    /** @return list<RequestHandlerInterface<mixed>>|null */
    private function read(): ?array
    {
        try {
            return StrictErrors::run(fn(): ?array => BuilderAccess::requestHandlers($this->builder));
        } catch (\Throwable) {
            return null;
        }
    }
}
