<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Json\Json;
use AgentCat\ToolCall;

/** Builds the `CallContext` an adapter would describe for a plain `tools/call` request. */
final class CallContexts
{
    /**
     * `request` is `{"method":"tools/call","params":{"name":…,"arguments":…}}`, `extra` is `{}`, and the `ToolCall`
     * carries the same name, the raw arguments and the client and protocol fields.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function sample(
        string $toolName,
        array $arguments = [],
        ?string $protocolVersion = null,
        bool $continuation = false,
        ?string $clientName = null,
        ?string $clientVersion = null,
    ): CallContext {
        $request = (object) [
            'method' => 'tools/call',
            'params' => (object) ['name' => $toolName, 'arguments' => self::arguments($arguments)],
        ];
        $toolCall = new ToolCall($toolName, $arguments, clientName: $clientName, clientVersion: $clientVersion, protocolVersion: $protocolVersion);

        return new CallContext($request, new \stdClass(), $toolCall, $protocolVersion, $continuation, $clientName, $clientVersion);
    }

    /**
     * The decoded JSON tree of PHP arguments; an empty array is `{}`.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function arguments(array $arguments): mixed
    {
        return Json::decode(json_encode($arguments === [] ? new \stdClass() : $arguments, JSON_THROW_ON_ERROR));
    }
}
