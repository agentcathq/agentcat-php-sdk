<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Wire\WireEncoder;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Transport\HttpTransport;
use PHPUnit\Framework\Assert;

/**
 * Drives a laravel/mcp server class the way `Registrar::startServer()` does for `Mcp::web()`: a new server per HTTP
 * request, built by the container, run on an `HttpTransport` over an `Illuminate\Http\Request`. No routing and no
 * middleware.
 */
final class LaravelHarness
{
    public const string MODERN_PROTOCOL_VERSION = '2026-07-28';

    private int $nextId = 1;

    /**
     * @param class-string<Server> $serverClass
     * @param (\Closure(Server): void)|null $track runs on each new server before `start()`
     */
    public function __construct(private readonly string $serverClass, private readonly ?\Closure $track = null) {}

    /**
     * One JSON-RPC request; the whole answer (`result` or `error`).
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    public function request(string $method, array $params = [], array $headers = []): \stdClass
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->answer(Request::create('/mcp/conformance', 'POST', server: $server, content: $this->body($method, $params)));
    }

    /**
     * One JSON-RPC request in an HTTP request that PHP received with exactly these server variables, as
     * `Request::capture()` builds it under PHP-FPM; the whole answer.
     *
     * @param array<string, string> $server
     * @param array<string, mixed> $params
     */
    public function requestFromServer(array $server, string $method, array $params = []): \stdClass
    {
        return $this->answer(new Request(server: $server, content: $this->body($method, $params)));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function body(string $method, array $params): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params], WireEncoder::FLAGS);
    }

    /** Builds a new server on an `HttpTransport` over the request, and runs it. */
    private function answer(Request $request): \stdClass
    {
        $transport = new HttpTransport($request);
        $instance = Container::getInstance()->make($this->serverClass, ['transport' => $transport]);
        Assert::assertInstanceOf(Server::class, $instance);
        if ($this->track instanceof \Closure) {
            ($this->track)($instance);
        }
        $instance->start();
        $answer = Json::decode((string) $transport->run()->getContent());
        Assert::assertInstanceOf(\stdClass::class, $answer);

        return $answer;
    }

    /** The `result` of a `tools/list` request; fails the test on a JSON-RPC error. */
    public function listTools(?string $cursor = null): \stdClass
    {
        $result = $this->request('tools/list', $cursor === null ? [] : ['cursor' => $cursor])->result ?? null;
        Assert::assertInstanceOf(\stdClass::class, $result);

        return $result;
    }

    /**
     * A 2026-07-28 `tools/call` (laravel/mcp's default era) with this client info in `_meta`.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<string, mixed> $meta more `_meta` keys
     * @param array<string, string> $headers
     * @param array{name: string, version: string}|null $clientInfo
     */
    public function callTool(string $name, array $arguments, array $meta = [], array $headers = [], ?array $clientInfo = ['name' => 'modern-client', 'version' => '2.0.0']): \stdClass
    {
        return $this->request('tools/call', ['name' => $name, 'arguments' => (object) $arguments, '_meta' => self::meta($meta, $clientInfo)], $headers);
    }

    /**
     * `_meta` of a 2026-07-28 request: the protocol version, client capabilities and client info.
     *
     * @param array<string, mixed> $meta
     * @param array{name: string, version: string}|null $clientInfo
     *
     * @return array<string, mixed>
     */
    public static function meta(array $meta, ?array $clientInfo): array
    {
        $meta['io.modelcontextprotocol/protocolVersion'] ??= self::MODERN_PROTOCOL_VERSION;
        $meta['io.modelcontextprotocol/clientCapabilities'] ??= new \stdClass();
        if ($clientInfo !== null) {
            $meta['io.modelcontextprotocol/clientInfo'] ??= $clientInfo; // a client info the test passes in $meta wins
        }

        return $meta;
    }
}
