<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Engine\TrackedServer;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers `tools/list` (TS listWrap): the handler the server's entry named before AgentCat's lists, then AgentCat
 * injects its parameters and appends get_more_tools to every page. Streams, JSON-RPC errors, exceptions and answers
 * without a tool list pass unchanged (02 AC-10).
 *
 * @internal
 */
final readonly class TrackedListTools implements Method
{
    /** @param class-string<Method> $original the handler the server's entry named before AgentCat's */
    public function __construct(private TrackedServer $server, private string $original, private Publisher $publisher) {}

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $this->publisher->pump(); // stdio servers deliver between requests
        $response = Delegate::handle($this->original, $request, $context); // throws pass unchanged (02 AC-10)
        if (!$response instanceof JsonRpcResponse || array_key_exists('error', $response->content)) {
            return $response; // a stream or a JSON-RPC error passes unchanged, like the mcp/sdk adapter's
        }
        try {
            // AgentCat changes only the result's `tools`; its other members (the cursor, `_meta`) stay.
            $tools = ToolListing::apply($this->server, LaravelTrees::tools($response));
            $result = $response->content['result'] ?? null;
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
