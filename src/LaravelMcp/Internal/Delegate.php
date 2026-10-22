<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use Illuminate\Container\Container;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * The handler that an entry of a server's method map named before AgentCat's, resolved and called the way
 * laravel/mcp's `Server::runMethodHandle()` does: `Container::make()` at each request, then `handle()` on what it
 * returns.
 *
 * @internal
 */
final class Delegate
{
    /**
     * Answers the request with the handler. An object that is not a `Method` but has its own `handle()` answers too,
     * and anything else fails with PHP's own `Error`, as without AgentCat. What the handler throws passes unchanged.
     *
     * @param class-string<Method> $class
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    public static function handle(string $class, JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        // @phpstan-ignore method.nonObject (laravel/mcp calls handle() on whatever the container returns), return.type (PHP checks the answer, as at laravel/mcp's runMethodHandle())
        return Container::getInstance()->make($class)->handle($request, $context);
    }
}
