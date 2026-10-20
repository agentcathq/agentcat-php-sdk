<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Json\JsonTree;

/**
 * What every adapter records from a `tools/call` request alike: the `parameters.request` and `parameters.extra`
 * trees of the event (D34), and the protocol version the call claims.
 *
 * @internal
 */
final class CallTrees
{
    /**
     * The protocol version the call claims in its `_meta`: never a version the session negotiated. Null
     * when the claim is not a non-empty string.
     *
     * @param array<array-key, mixed>|null $meta
     */
    public static function claimedVersion(?array $meta): ?string
    {
        $claim = $meta[Constants::META_PROTOCOL_VERSION_KEY] ?? null;

        return is_string($claim) && $claim !== '' ? $claim : null;
    }

    /**
     * `{"method":"tools/call","params":{…}}`, raw arguments included. The arguments are an object: empty arguments are
     * `{}` and integer keys stay keys. A sent `_meta` is an object too, so a sent `{}` stays `{}`.
     *
     * @param array<array-key, mixed> $params the request's params, as the library holds them
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed>|null $meta null when the call sent none
     *
     * @throws \JsonException for a value JSON cannot carry; a request decoded from JSON holds none
     */
    public static function request(array $params, array $arguments, ?array $meta): \stdClass
    {
        $params['arguments'] = (object) $arguments;
        if ($meta !== null) {
            $params['_meta'] = (object) $meta;
        }
        $tree = new \stdClass();
        $tree->method = 'tools/call';
        $tree->params = JsonTree::of((object) $params);

        return $tree;
    }

    /**
     * `{sessionId?, requestId, _meta?, requestInfo?, authInfo?}`, in this order (D34).
     *
     * @param string|null $sessionId the transport session (`Mcp-Session-Id`), when there is one
     * @param array<array-key, mixed>|null $meta null when the call sent none
     * @param \stdClass|null $authInfo `{scopes, claims}` of the request's access token, when there is one
     *
     * @throws \JsonException for a value JSON cannot carry; a request decoded from JSON holds none
     */
    public static function extra(?string $sessionId, int|string $requestId, ?array $meta, ?HttpContext $http, ?\stdClass $authInfo = null): \stdClass
    {
        $extra = new \stdClass();
        if ($sessionId !== null) {
            $extra->sessionId = $sessionId;
        }
        $extra->requestId = $requestId;
        if ($meta !== null) {
            $extra->_meta = JsonTree::of((object) $meta);
        }
        if ($http instanceof HttpContext) {
            $extra->requestInfo = $http->toRequestInfo();
        }
        if ($authInfo instanceof \stdClass) {
            $extra->authInfo = $authInfo;
        }

        return $extra;
    }
}
