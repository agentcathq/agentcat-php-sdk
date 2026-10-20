<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Engine\CallTrees;
use AgentCat\Internal\Engine\ClientIdentity;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\ToolCall;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * Reads what an event records and the hooks see from a laravel/mcp `tools/call` request:
 * the client from the call's `_meta`, else from the legacy handshake of the same server; the protocol version only
 * from the call's `_meta`. laravel/mcp 1.x has no transport session and no MCP-level auth object.
 *
 * @internal
 */
final readonly class LaravelRequestReader
{
    /**
     * @param \Closure(): ?HttpContext $http the HTTP request the server answers; null on stdio. Never throws.
     */
    public function __construct(private \Closure $http, private HandshakeClient $handshake) {}

    /** The called tool's name; '' when the request names none. */
    public static function toolName(JsonRpcRequest $request): string
    {
        $name = $request->params['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    /**
     * The request's `params.arguments` as laravel/mcp decoded them; empty when they are not an array.
     *
     * @return array<array-key, mixed>
     */
    public static function arguments(JsonRpcRequest $request): array
    {
        $arguments = $request->params['arguments'] ?? null;

        return is_array($arguments) ? $arguments : [];
    }

    /** @throws \JsonException for a value JSON cannot carry; a request decoded from JSON holds none */
    public function read(JsonRpcRequest $request, ServerContext $context): CallContext
    {
        $meta = $request->meta();
        [$clientName, $clientVersion] = ClientIdentity::pick($meta[Constants::META_CLIENT_INFO_KEY] ?? null, $this->handshake->clientInfo());
        $protocolVersion = CallTrees::claimedVersion($meta);
        $http = ($this->http)();
        $arguments = self::arguments($request);

        return new CallContext(
            request: CallTrees::request($request->params, $arguments, $meta),
            extra: CallTrees::extra(null, $request->id, $meta, $http),
            toolCall: new ToolCall(
                toolName: self::toolName($request),
                arguments: $arguments,
                meta: $meta ?? [],
                headers: $http instanceof HttpContext ? $http->headers : [],
                clientName: $clientName,
                clientVersion: $clientVersion,
                protocolVersion: $protocolVersion,
                request: $request,
                context: $context,
            ),
            protocolVersion: $protocolVersion,
            continuation: isset($request->params['inputResponses']),
            clientName: $clientName,
            clientVersion: $clientVersion,
        );
    }
}
