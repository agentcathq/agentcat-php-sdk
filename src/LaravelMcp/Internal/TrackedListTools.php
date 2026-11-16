<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\ServerInfo\Heartbeat;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers `tools/list` (TS listWrap): the handler the server's entry named before AgentCat's lists, then AgentCat
 * injects its parameters into every page and appends get_more_tools to the last one (D66). Streams, JSON-RPC errors,
 * exceptions and answers without a tool list pass unchanged (ac-02-10). Once the request is answered, the server info
 * heartbeat gets its check (`Heartbeat`).
 *
 * @internal
 */
final readonly class TrackedListTools implements Method
{
    /**
     * @param class-string<Method> $original the handler the server's entry named before AgentCat's
     * @param \Closure(): array<mixed> $methods the server's method map of this moment, for the server info heartbeat
     */
    public function __construct(private TrackedServer $server, private string $original, private Publisher $publisher, private \Closure $methods) {}

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $this->publisher->pump(); // stdio servers deliver between requests

        try {
            return $this->answer($request, $context);
        } finally {
            Heartbeat::onToolList($this->server, fn(): LaravelServerInfo => new LaravelServerInfo(['tools/list' => $this->original] + ($this->methods)(), $context)); // never throws
        }
    }

    /** @return iterable<JsonRpcResponse>|JsonRpcResponse */
    private function answer(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $response = Delegate::handle($this->original, $request, $context); // throws pass unchanged (ac-02-10)
        if (!$response instanceof JsonRpcResponse || array_key_exists('error', $response->content)) {
            return $response; // a stream or a JSON-RPC error passes unchanged, like the mcp/sdk adapter's
        }
        try {
            // AgentCat changes only the result's `tools`; its other members (the cursor, `_meta`) stay.
            $result = $response->content['result'] ?? null;
            $lastPage = ToolListing::nextCursor(is_array($result) ? ($result['nextCursor'] ?? null) : null) === null;
            $tools = ToolListing::apply($this->server, LaravelTrees::tools($response), $lastPage);
            if ($tools === null || !is_array($result)) {
                return $response;
            }

            return new JsonRpcResponse([...$response->content, 'result' => [...$result, 'tools' => LaravelTrees::native($tools)]]);
        } catch (\Throwable $e) {
            ToolListing::injectionFailed($e);

            return $response;
        }
    }
}
