<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use PHPUnit\Framework\Assert;

/**
 * The MCP client of a scenario, over real HTTP. In the handshake era it opens with `initialize` and sends every request
 * in that session; in the 2026-07-28 era every request carries the envelope, with this client's info in its `_meta`.
 * Every request carries the lane's headers.
 */
final readonly class ScenarioClient
{
    /** The client info of every scenario, in the handshake or in the envelope. */
    public const array CLIENT_INFO = ['name' => 'agentcat-e2e-client', 'version' => '1.0.0'];

    /** @param array<string, string> $headers */
    private function __construct(private McpHttpClient $http, private bool $modern, private array $headers) {}

    /**
     * A client of the MCP endpoint `$url`, after the handshake unless `$modern`.
     *
     * @param array<string, string> $headers the headers of every request
     */
    public static function connect(string $url, bool $modern, array $headers = []): self
    {
        $http = new McpHttpClient($url);
        if (!$modern) {
            $http->initialize(self::CLIENT_INFO['name'], self::CLIENT_INFO['version'], $headers);
        }

        return new self($http, $modern, $headers);
    }

    /**
     * The tools of a `tools/list` answer.
     *
     * @return list<\stdClass>
     */
    public function listTools(): array
    {
        $result = $this->modern ? $this->http->listToolsModern(self::CLIENT_INFO, $this->headers) : $this->http->listTools($this->headers);
        $tools = $result->tools ?? null;
        Assert::assertIsList($tools);

        return array_map(static function (mixed $tool): \stdClass {
            Assert::assertInstanceOf(\stdClass::class, $tool);

            return $tool;
        }, $tools);
    }

    /**
     * The `result` of a `tools/call`; a JSON-RPC error fails the test.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<string, mixed>    $meta      the call's own `_meta` (handshake era only)
     */
    public function call(string $name, array $arguments, array $meta = []): \stdClass
    {
        Assert::assertFalse($this->modern && $meta !== [], 'a 2026-07-28 call carries the envelope in its _meta');
        $answer = $this->modern
            ? $this->http->callToolModern($name, $arguments, self::CLIENT_INFO, $this->headers)
            : $this->http->callTool($name, $arguments, $this->headers, $meta);

        return HttpHarness::result($answer['message']);
    }

    /** The `Mcp-Session-Id` of the handshake; null in the 2026-07-28 era. */
    public function sessionId(): ?string
    {
        return $this->http->sessionId();
    }
}
