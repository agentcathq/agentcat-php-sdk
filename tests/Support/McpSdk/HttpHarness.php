<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\Json\Json;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Assert;

/**
 * Drives a real mcp/sdk server in process: each message is one PSR-7 request through a fresh
 * `StreamableHttpTransport`, with no sockets. Handshake-era calls carry the `Mcp-Session-Id` that `initialize()`
 * received; `modern()` sends one self-contained 2026-07-28 request instead.
 */
final class HttpHarness
{
    public const string MODERN_PROTOCOL_VERSION = '2026-07-28';

    private const array MODERN_CLIENT = ['name' => 'modern-client', 'version' => '2.0.0'];

    private readonly Psr17Factory $factory;

    private int $nextId = 1;

    private ?string $sessionId = null;

    private string $protocolVersion = '2025-06-18';

    public function __construct(private readonly Server $server)
    {
        $this->factory = new Psr17Factory();
    }

    /** The `Mcp-Session-Id` the server issued at `initialize()`; null before. */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** Sends `initialize` and `notifications/initialized`; returns the `initialize` result. */
    public function initialize(string $clientName = 'harness-client', string $clientVersion = '1.0.0', string $protocolVersion = '2025-06-18'): \stdClass
    {
        $result = self::result($this->request('initialize', [
            'protocolVersion' => $protocolVersion,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => $clientName, 'version' => $clientVersion],
        ]));
        $this->protocolVersion = is_string($result->protocolVersion ?? null) ? $result->protocolVersion : $protocolVersion;

        $response = $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], [], true);
        Assert::assertSame(202, $response->getStatusCode(), 'notifications/initialized');
        Assert::assertSame('', (string) $response->getBody(), 'notifications/initialized');

        return $result;
    }

    /**
     * One JSON-RPC request; returns the whole answer (`result` or `error`).
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    public function request(string $method, array $params = [], array $headers = []): \stdClass
    {
        return $this->send($method, $params, $headers, true);
    }

    /** The `result` of a `tools/list` request. */
    public function listTools(?string $cursor = null): \stdClass
    {
        return self::result($this->request('tools/list', $cursor === null ? [] : ['cursor' => $cursor]));
    }

    /**
     * The whole answer to a `tools/call` request: `result` or `error`.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<string, mixed>|null $meta
     */
    public function callTool(string $name, array $arguments, ?array $meta = null): \stdClass
    {
        $params = ['name' => $name, 'arguments' => (object) $arguments];
        if ($meta !== null) {
            $params['_meta'] = (object) $meta;
        }

        return $this->request('tools/call', $params);
    }

    /**
     * One 2026-07-28 request: no session, the protocol version, client capabilities and client info in
     * `params._meta`, and the standard headers. Returns the whole answer.
     *
     * @param array<string, mixed> $params
     * @param array{name: string, version: string}|null $clientInfo
     */
    public function modern(string $method, array $params, ?string $name = null, ?array $clientInfo = self::MODERN_CLIENT): \stdClass
    {
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $meta['io.modelcontextprotocol/protocolVersion'] = self::MODERN_PROTOCOL_VERSION;
        $meta['io.modelcontextprotocol/clientCapabilities'] = new \stdClass();
        if ($clientInfo !== null) {
            $meta['io.modelcontextprotocol/clientInfo'] = $clientInfo;
        }
        $params['_meta'] = $meta;
        $headers = ['MCP-Protocol-Version' => self::MODERN_PROTOCOL_VERSION, 'Mcp-Method' => $method];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }

        return $this->send($method, $params, $headers, false);
    }

    /**
     * @param array<array-key, mixed> $arguments
     * @param array{name: string, version: string}|null $clientInfo
     */
    public function callToolModern(string $name, array $arguments, ?array $clientInfo = self::MODERN_CLIENT): \stdClass
    {
        return $this->modern('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $name, $clientInfo);
    }

    /** The `result` of a 2026-07-28 `tools/list` request. */
    public function listToolsModern(): \stdClass
    {
        return self::result($this->modern('tools/list', []));
    }

    /** The `result` of an answer; fails the test on a JSON-RPC error. */
    public static function result(\stdClass $message): \stdClass
    {
        $result = $message->result ?? null;
        Assert::assertInstanceOf(\stdClass::class, $result, (string) json_encode($message));

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function send(string $method, array $params, array $headers, bool $withSession): \stdClass
    {
        $id = $this->nextId++;
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params];
        $response = $this->post($message, $headers, $withSession);

        return $this->decode((string) $response->getBody(), $response->getHeaderLine('Content-Type'), $id);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, string> $headers
     */
    private function post(array $message, array $headers, bool $withSession): Response
    {
        $request = $this->factory->createServerRequest('POST', 'http://127.0.0.1/mcp')
            ->withHeader('Host', '127.0.0.1')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream(json_encode($message, JSON_THROW_ON_ERROR)));
        if ($withSession && $this->sessionId !== null) {
            $request = $request->withHeader('Mcp-Session-Id', $this->sessionId)->withHeader('MCP-Protocol-Version', $this->protocolVersion);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = $this->server->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
        Assert::assertInstanceOf(Response::class, $response);
        $session = $response->getHeaderLine('Mcp-Session-Id');
        if ($session !== '') {
            $this->sessionId = $session;
        }

        return $response;
    }

    /** A JSON body, or the `data:` line of an SSE body that answers request `$id`. */
    private function decode(string $body, string $contentType, int $id): \stdClass
    {
        if (str_starts_with($contentType, 'text/event-stream')) {
            foreach (explode("\n", $body) as $line) {
                if (!str_starts_with($line, 'data:')) {
                    continue;
                }
                $message = Json::decode(trim(substr($line, 5)));
                if ($message instanceof \stdClass && ($message->id ?? null) === $id) {
                    return $message;
                }
            }
            Assert::fail(sprintf('No SSE message answers request %d: %s', $id, $body));
        }
        $message = Json::decode($body);
        Assert::assertInstanceOf(\stdClass::class, $message, $body);

        return $message;
    }
}
