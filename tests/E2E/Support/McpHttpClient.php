<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Support;

use AgentCat\Tests\Support\McpSdk\HttpHarness;
use PHPUnit\Framework\Assert;

/**
 * A raw JSON-RPC client for an MCP server over real HTTP (curl). Handshake-era calls carry the `Mcp-Session-Id` that
 * `initialize()` received; `callToolModern()` sends one self-contained 2026-07-28 request, like `HttpHarness`.
 */
final class McpHttpClient
{
    private const int TIMEOUT_SECONDS = 10;

    private const string HANDSHAKE_PROTOCOL_VERSION = '2025-06-18';

    private int $nextId = 1;

    private ?string $sessionId = null;

    private string $protocolVersion = self::HANDSHAKE_PROTOCOL_VERSION;

    /** @param string $url the MCP endpoint */
    public function __construct(private readonly string $url) {}

    /** The `Mcp-Session-Id` the server issued at `initialize()`; null before. */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * POSTs one JSON-RPC message, with the session headers once `initialize()` ran. A header in `$headers` replaces
     * the default of the same name; a list value sends one header line per item. The response header names are in
     * lower case, and repeated ones are joined with `, `.
     *
     * @param array<string, mixed> $message
     * @param array<string, string|list<string>> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, receivedAt: float}
     */
    public function post(array $message, array $headers = []): array
    {
        return $this->exchange($message, $headers, true);
    }

    /** Sends `initialize` and `notifications/initialized`, and keeps the `Mcp-Session-Id`. */
    public function initialize(string $name = 'e2e-client', string $version = '1.0.0'): void
    {
        $result = HttpHarness::result($this->request('initialize', [
            'protocolVersion' => self::HANDSHAKE_PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => $name, 'version' => $version],
        ], [], true)['message']);
        $this->protocolVersion = is_string($result->protocolVersion ?? null) ? $result->protocolVersion : self::HANDSHAKE_PROTOCOL_VERSION;
        Assert::assertNotNull($this->sessionId, 'initialize issued no Mcp-Session-Id');

        $response = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        Assert::assertSame(202, $response['status'], 'notifications/initialized');
        Assert::assertSame('', $response['body'], 'notifications/initialized');
    }

    /**
     * The `result` of a `tools/list` request.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function listTools(array $headers = []): \stdClass
    {
        return HttpHarness::result($this->request('tools/list', [], $headers, true)['message']);
    }

    /**
     * The `result` of a 2026-07-28 `tools/list` request: no session, the envelope and headers of `HttpHarness::modern()`.
     *
     * @param array{name: string, version: string} $clientInfo
     * @param array<string, string|list<string>> $headers
     */
    public function listToolsModern(array $clientInfo, array $headers = []): \stdClass
    {
        return HttpHarness::result($this->request('tools/list', HttpHarness::modernParams([], $clientInfo), [...HttpHarness::modernHeaders('tools/list', null), ...$headers], false)['message']);
    }

    /**
     * The whole answer to a `tools/call` request (`result` or `error`), and when it was read.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<string, string|list<string>> $headers
     * @param array<string, mixed> $meta the request's `_meta`, if any
     *
     * @return array{message: \stdClass, receivedAt: float}
     */
    public function callTool(string $name, array $arguments, array $headers = [], array $meta = []): array
    {
        $params = ['name' => $name, 'arguments' => (object) $arguments];

        return $this->request('tools/call', $meta === [] ? $params : [...$params, '_meta' => $meta], $headers, true);
    }

    /**
     * A 2026-07-28 `tools/call`: no session, the envelope and headers of `HttpHarness::modern()`.
     *
     * @param array<array-key, mixed> $arguments
     * @param array{name: string, version: string} $clientInfo
     * @param array<string, string|list<string>> $headers
     *
     * @return array{message: \stdClass, receivedAt: float}
     */
    public function callToolModern(string $name, array $arguments, array $clientInfo, array $headers = []): array
    {
        $params = HttpHarness::modernParams(['name' => $name, 'arguments' => (object) $arguments], $clientInfo);

        return $this->request('tools/call', $params, [...HttpHarness::modernHeaders('tools/call', $name), ...$headers], false);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string|list<string>> $headers
     *
     * @return array{message: \stdClass, receivedAt: float}
     */
    private function request(string $method, array $params, array $headers, bool $withSession): array
    {
        $id = $this->nextId++;
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params];
        $response = $this->exchange($message, $headers, $withSession);

        return [
            'message' => HttpHarness::decode($response['body'], $response['headers']['content-type'] ?? '', $id),
            'receivedAt' => $response['receivedAt'],
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, string|list<string>> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, receivedAt: float}
     */
    private function exchange(array $message, array $headers, bool $withSession): array
    {
        $lines = ['content-type' => ['Content-Type', ['application/json']], 'accept' => ['Accept', ['application/json, text/event-stream']]];
        if ($withSession && $this->sessionId !== null) {
            $lines['mcp-session-id'] = ['Mcp-Session-Id', [$this->sessionId]];
            $lines['mcp-protocol-version'] = ['MCP-Protocol-Version', [$this->protocolVersion]];
        }
        foreach ($headers as $name => $value) {
            $lines[strtolower($name)] = [$name, is_string($value) ? [$value] : $value];
        }
        $headerLines = ['Expect:']; // no 100-continue round trip
        foreach ($lines as [$name, $values]) {
            foreach ($values as $value) {
                $headerLines[] = $name . ': ' . $value;
            }
        }

        $responseHeaders = [];
        $handle = curl_init($this->url);
        Assert::assertNotFalse($handle, 'curl_init');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($message, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($handle);
        $receivedAt = microtime(true);
        Assert::assertIsString($body, 'curl: ' . curl_error($handle));
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $session = $responseHeaders['mcp-session-id'] ?? '';
        if ($session !== '') {
            $this->sessionId = $session;
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body, 'receivedAt' => $receivedAt];
    }
}
