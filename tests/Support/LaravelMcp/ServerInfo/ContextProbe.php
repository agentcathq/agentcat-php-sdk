<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** A `resources/list` handler that keeps a weak reference to the server context of each call. */
final class ContextProbe implements Method
{
    /** @var list<\WeakReference<ServerContext>> */
    private static array $contexts = [];

    /** Forgets the calls so far. */
    public static function reset(): void
    {
        self::$contexts = [];
    }

    /**
     * The contexts of the calls so far that something still refers to; null for one that is gone.
     *
     * @return list<ServerContext|null>
     */
    public static function contexts(): array
    {
        return array_map(static fn(\WeakReference $context): ?ServerContext => $context->get(), self::$contexts);
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        self::$contexts[] = \WeakReference::create($context);

        return JsonRpcResponse::result($request->id, ['resources' => []]);
    }
}
