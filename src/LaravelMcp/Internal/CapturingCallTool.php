<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\ThrowableStash;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * laravel/mcp's `CallTool`, whose tool invoker also keeps the tool's own exception for the request: a copy of
 * `CallTool::handle()` (laravel/mcp 1.0.0 to 1.1.0) with `CapturingToolInvoker`. The contract
 * test `ExtendedClassesTest` keeps both answers equal.
 *
 * @internal
 */
final class CapturingCallTool extends CallTool
{
    public function __construct(private readonly ThrowableStash $stash) {}

    /**
     * @return JsonRpcResponse|\Generator<JsonRpcResponse>
     *
     * @throws JsonRpcException
     */
    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): \Generator|JsonRpcResponse
    {
        $name = $request->get('name');
        if (!is_scalar($name)) {
            // No name, or one that no tool has: the library answers, and fails, on its own, with its own message.
            return parent::handle($request, $context);
        }
        $tool = $context->tools()->first(
            static fn(Tool $tool): bool => $tool->name() === $name,
            static fn(): never => throw new JsonRpcException(sprintf('Tool [%s] not found.', (string) $name), -32602, $request->id),
        );

        return (new CapturingToolInvoker())->capturingInto($this->stash, $request)->invoke($tool, $request);
    }
}
