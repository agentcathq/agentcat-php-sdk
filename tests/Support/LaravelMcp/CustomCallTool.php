<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * A customer's own `tools/call` handler, like Laravel Boost's `CallToolWithExecutor`: it counts its calls, records the
 * arguments of the `Request` its constructor gets, and answers with laravel/mcp's `CallTool`.
 */
final class CustomCallTool implements Method
{
    public static int $calls = 0;

    /** @var list<array<string, mixed>> the arguments of the constructor's `Request`, one entry per call */
    public static array $constructed = [];

    public function __construct(Request $request)
    {
        self::$constructed[] = $request->all();
    }

    public static function reset(): void
    {
        self::$calls = 0;
        self::$constructed = [];
    }

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        ++self::$calls;
        return Container::getInstance()->make(CallTool::class)->handle($request, $context);
    }
}
