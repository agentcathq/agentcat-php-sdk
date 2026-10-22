<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * One analytics event, as the pipeline and the `redactEvent` hook see it.
 *
 * JSON-valued fields (`parameters`, `response`, `error`, `identifyActorData`, `properties`) hold decoded JSON:
 * objects are `\stdClass`, arrays are lists. A `redactEvent` hook may change any field and return the event,
 * or return null to drop it; `id`, `sessionId`, `projectId`, `eventType` and `timestamp` are restored after
 * the hook. `timestamp` is UTC and is sent with millisecond precision.
 */
final class Event
{
    /**
     * @param string $id Event ID.
     * @param string $sessionId Session ID; empty means the server assigns a session.
     * @param string|null $projectId Project ID; null in telemetry-only mode.
     * @param string $eventType Event type, for example `mcp:tools/call`.
     * @param \DateTimeImmutable $timestamp When the event happened (UTC).
     * @param int|float|null $duration Duration in milliseconds.
     * @param int|null $inputTokens Estimated token count of the request side.
     * @param int|null $outputTokens Estimated token count of the response side.
     * @param string|null $ipAddress Client IP address.
     * @param string|null $sdkLanguage Language of the SDK (`PHP`).
     * @param string|null $agentcatVersion Version of the SDK.
     * @param string|null $serverName Name of the MCP server.
     * @param string|null $serverVersion Version of the MCP server.
     * @param string|null $clientName Name of the MCP client.
     * @param string|null $clientVersion Version of the MCP client.
     * @param string|null $identifyActorGivenId Identified actor ID.
     * @param string|null $identifyActorName Identified actor name.
     * @param mixed $identifyActorData Identified actor data (JSON object).
     * @param string|null $resourceName Tool or resource name.
     * @param mixed $parameters Request parameters (JSON).
     * @param mixed $response Response (JSON).
     * @param string|null $userIntent Why the actor made the call.
     * @param bool|null $isError Whether the call failed.
     * @param mixed $error Error details (JSON object).
     * @param array<array-key, string>|null $tags Tags (string values only).
     * @param mixed $properties Custom properties (JSON object).
     * @param string|null $actorId Legacy actor ID; the wire sends `actorId ?: identifyActorGivenId`.
     */
    public function __construct(
        public string $id = '',
        public string $sessionId = '',
        public ?string $projectId = null,
        public string $eventType = '',
        public \DateTimeImmutable $timestamp = new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        public int|float|null $duration = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $ipAddress = null,
        public ?string $sdkLanguage = null,
        public ?string $agentcatVersion = null,
        public ?string $serverName = null,
        public ?string $serverVersion = null,
        public ?string $clientName = null,
        public ?string $clientVersion = null,
        public ?string $identifyActorGivenId = null,
        public ?string $identifyActorName = null,
        public mixed $identifyActorData = null,
        public ?string $resourceName = null,
        public mixed $parameters = null,
        public mixed $response = null,
        public ?string $userIntent = null,
        public ?bool $isError = null,
        public mixed $error = null,
        public ?array $tags = null,
        public mixed $properties = null,
        public ?string $actorId = null,
    ) {}
}
