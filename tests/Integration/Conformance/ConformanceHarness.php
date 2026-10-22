<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Conformance;

use AgentCat\Event;

/**
 * One tracked server of an MCP library, for the adapter conformance suite: the requests a client sends, and what
 * AgentCat and the tools recorded.
 */
interface ConformanceHarness
{
    /** The `result` of a `tools/list` request; fails the test on a JSON-RPC error. */
    public function listTools(): \stdClass;

    /**
     * The whole answer to a `tools/call` request: `result` or `error`. Without `$meta`, the library's everyday call;
     * with it, a 2026-07-28 call whose `_meta` holds these keys plus the protocol version and client capabilities
     * that era requires, on the same server instance.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<string, mixed> $meta
     */
    public function callTool(string $name, array $arguments, array $meta = []): \stdClass;

    /**
     * Delivers everything queued, then returns every event delivered, in order.
     *
     * @return list<Event>
     */
    public function events(): array;

    /**
     * The arguments that each call of the tool received, in order.
     *
     * @return list<array<array-key, mixed>>
     */
    public function received(string $tool): array;

    /**
     * The SDK log lines, in order.
     *
     * @return list<string>
     */
    public function lines(): array;
}
