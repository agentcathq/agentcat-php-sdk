<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Engine\CallTrees;
use AgentCat\Internal\Engine\ClientIdentity;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\ToolCall;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;

/**
 * Reads what an event records and the hooks see from an mcp/sdk `tools/call` request and its session.
 *
 * The modern era (2026-07-28) puts a `RequestMeta` in a fresh session for each request; the handshake era keeps one
 * session per `Mcp-Session-Id`, with the `initialize` client info.
 *
 * @internal
 */
final readonly class RequestReader
{
    /** The session key under which mcp/sdk main keeps the request's access token; 0.8.1 has no such class. */
    private const string ACCESS_TOKEN = 'Mcp\Server\Authorization\AccessToken';

    /** @var \Closure(): ?HttpContext */
    private \Closure $http;

    /** @param (\Closure(): ?HttpContext)|null $http the HTTP request of the call; default: the SAPI globals */
    public function __construct(?\Closure $http = null)
    {
        $this->http = $http ?? HttpContext::capture(...);
    }

    /** @throws \JsonException for a value JSON cannot carry; a request decoded from JSON holds none */
    public function read(CallToolRequest $request, SessionInterface $session): CallContext
    {
        $meta = $request->getMeta();
        $requestMeta = $session->get(RequestMeta::class);
        $requestMeta = $requestMeta instanceof RequestMeta ? $requestMeta : null; // set in the modern era only
        [$clientName, $clientVersion] = ClientIdentity::pick(
            $requestMeta?->clientInfo,
            $meta[Constants::META_CLIENT_INFO_KEY] ?? null,
            $session->get('client_info'),
        );
        // Never the session's negotiated `protocol_version` (02 §3.3): only what the call itself claims.
        $protocolVersion = $requestMeta instanceof RequestMeta ? $requestMeta->protocolVersion : CallTrees::claimedVersion($meta);
        $transportSessionId = $requestMeta instanceof RequestMeta ? null : (string) $session->getId(); // the Mcp-Session-Id
        $http = ($this->http)();
        $tree = CallTrees::request($request->jsonSerialize()['params'] ?? [], $request->arguments, $meta);
        $extra = CallTrees::extra($transportSessionId, $request->getId(), $meta, $http, $this->authInfo($session->get(self::ACCESS_TOKEN)));

        return new CallContext(
            request: $tree,
            extra: $extra,
            toolCall: new ToolCall(
                toolName: $request->name,
                arguments: $request->arguments,
                meta: $meta ?? [],
                headers: $http instanceof HttpContext ? $http->headers : [],
                clientName: $clientName,
                clientVersion: $clientVersion,
                protocolVersion: $protocolVersion,
                transportSessionId: $transportSessionId,
                request: $request,
                context: $session,
            ),
            protocolVersion: $protocolVersion,
            continuation: $session->get(InputContext::class) instanceof InputContext,
            clientName: $clientName,
            clientVersion: $clientVersion,
        );
    }

    /**
     * `{scopes, claims}` of an access token (mcp/sdk main), read by duck typing so the class need not exist.
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    private function authInfo(mixed $token): ?\stdClass
    {
        if (!is_object($token) || !method_exists($token, 'getScopes') || !method_exists($token, 'getClaims')) {
            return null;
        }
        $scopes = $token->getScopes();
        $claims = $token->getClaims();
        $info = new \stdClass();
        $info->scopes = Trees::of(is_array($scopes) ? array_values($scopes) : []);
        $info->claims = Trees::of(is_array($claims) ? (object) $claims : new \stdClass());

        return $info;
    }
}
