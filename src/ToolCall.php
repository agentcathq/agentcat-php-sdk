<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * One tool call, as the hooks in {@see Options} see it. The hooks run after your tool has answered,
 * not while the client waits.
 */
final readonly class ToolCall
{
    /**
     * @param string $toolName Name of the tool the client called.
     * @param array<array-key, mixed> $arguments The raw call arguments, including the parameters AgentCat injects.
     * @param array<array-key, mixed> $meta The raw `_meta` of the call.
     * @param array<string, string> $headers HTTP request headers with lower-case names; empty on stdio.
     * @param string|null $clientName Name of the MCP client, when it is known.
     * @param string|null $clientVersion Version of the MCP client, when it is known.
     * @param string|null $protocolVersion MCP protocol version of the call, when it is known.
     * @param string|null $transportSessionId The `Mcp-Session-Id` of the transport session; never the AgentCat session ID.
     * @param object|null $request The request object of your MCP library, for details this class does not expose.
     * @param object|null $context The session or context object of your MCP library, for details this class does not expose.
     */
    public function __construct(
        public string $toolName,
        public array $arguments,
        public array $meta = [],
        public array $headers = [],
        public ?string $clientName = null,
        public ?string $clientVersion = null,
        public ?string $protocolVersion = null,
        public ?string $transportSessionId = null,
        public ?object $request = null,
        public ?object $context = null,
    ) {}
}
