<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\ServerInfo\Heartbeat;
use Illuminate\Container\Container;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers `tools/call` (TS callWrap): `CallInterceptor` resolves the handles, strips them, lets the handler the
 * server's entry named before AgentCat's run the tool, decorates the answer and queues one event. A customer's handler
 * answers itself. laravel/mcp's own `CallTool` is built by the container at each call, as laravel/mcp does, so a
 * binding, an `extend()` decorator (an authorization check, say) and the `resolving()` callbacks of `CallTool` run:
 * when the container builds exactly `CallTool`, AgentCat answers with `CapturingCallTool`, which keeps the tool's
 * exception; anything else answers itself. A call without a string tool name goes to the handler untraced. Once the
 * request is answered, the server info heartbeat gets its check (`Heartbeat`).
 *
 * @internal
 */
final readonly class TrackedCallTool implements Method
{
    /**
     * @param class-string<Method> $original the handler the server's `tools/call` entry named before AgentCat's
     * @param class-string<Method>|null $listOriginal the same for `tools/list`, for the registry rebuild; null when the
     *                                                entry names no method class
     * @param \Closure(): array<mixed> $methods the server's method map of this moment, for the server info heartbeat
     */
    public function __construct(
        private TrackedServer $server,
        private string $original,
        private ?string $listOriginal,
        private CallInterceptor $interceptor,
        private Publisher $publisher,
        private ThrowableStash $stash,
        private LaravelRequestReader $reader,
        private \Closure $methods,
    ) {}

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $this->publisher->pump(); // stdio servers deliver between requests

        try {
            return $this->answer($request, $context);
        } finally {
            $name = $request->get('name');
            // A `tools/list` entry that names no method class has no handler AgentCat can call: the heartbeat then has no tool list.
            Heartbeat::onToolCall($this->server, is_string($name) ? $name : '', fn(): LaravelServerInfo => new LaravelServerInfo(['tools/list' => $this->listOriginal] + ($this->methods)(), $context)); // never throws
        }
    }

    /** @return iterable<JsonRpcResponse>|JsonRpcResponse */
    private function answer(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $original = $this->original;
        $stash = $this->stash;
        $delegate = $original === CallTool::class
            ? static fn(JsonRpcRequest $sent): iterable|JsonRpcResponse => self::callTool($stash, $sent, $context)
            : static fn(JsonRpcRequest $sent): iterable|JsonRpcResponse => Delegate::handle($original, $sent, $context);
        if (!is_string($request->get('name'))) {
            return $delegate($request); // TypeScript and mcp/sdk reject it before any handler: no event (D15)
        }
        $list = $this->listOriginal;
        $seam = new LaravelCallSeam(
            $request,
            $context,
            $delegate,
            $list === null ? null : static fn(JsonRpcRequest $listing): iterable|JsonRpcResponse => Delegate::handle($list, $listing, $context),
            $stash,
            $this->reader,
        );

        return $this->interceptor->intercept($this->server, $seam);
    }

    /**
     * laravel/mcp's `CallTool` as the container builds it. The container's extenders and resolving callbacks are not
     * public, so it is always built; with none, it is exactly `CallTool`.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    private static function callTool(ThrowableStash $stash, JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $handler = Container::getInstance()->make(CallTool::class);

        return $handler::class === CallTool::class
            ? (new CapturingCallTool($stash))->handle($request, $context)
            : $handler->handle($request, $context);
    }
}
