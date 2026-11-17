<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** A list handler of the customer's that fails; it counts its calls. */
final class FailingMethod implements Method
{
    public static int $calls = 0;

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        ++self::$calls;

        throw new \RuntimeException('the handler failed');
    }
}
