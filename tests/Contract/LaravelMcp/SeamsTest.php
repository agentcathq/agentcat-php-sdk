<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\EmptyServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\Group;

/** The public and internal behavior of laravel/mcp that the adapter relies on, checked against the installed version. */
#[Group('contract')]
final class SeamsTest extends LaravelTestCase
{
    public function testMethodEntriesAreResolvedThroughTheContainerForEveryMessage(): void
    {
        $method = new RecordingMethod();
        $this->app?->instance('contract.any-key', $method);
        $transport = new LineTransport();
        $server = $this->server($transport);
        // @phpstan-ignore argument.type (laravel/mcp resolves the entry with Container::make(), which takes any bound key)
        $server->addMethod('tools/list', 'contract.any-key');
        $server->start();

        $answer = $transport->deliver(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass()]);
        $transport->deliver(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()]);

        $this->assertSame(1, $answer?->id);
        $this->assertCount(2, $method->requests);
    }

    public function testTheMcpRequestIsBoundWhileAMethodRuns(): void
    {
        $method = new RecordingMethod(static fn(): mixed => Container::getInstance()->make('mcp.request'));
        $this->app?->instance('contract.mcp-request', $method);
        $transport = new LineTransport();
        $server = $this->server($transport);
        // @phpstan-ignore argument.type (see above)
        $server->addMethod('tools/call', 'contract.mcp-request');
        $server->start();

        $transport->deliver(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'x', 'arguments' => ['a' => 1]]]);

        $seen = $method->seen[0] ?? null;
        $this->assertInstanceOf(Request::class, $seen);
        $this->assertSame(['a' => 1], $seen->all());
        $this->assertFalse($this->app?->bound('mcp.request'), 'the binding outlives the method');
    }

    public function testTheContextListsTheServersToolsAsToolObjects(): void
    {
        $server = $this->server(new LineTransport());

        $this->assertSame(['empty-server', '1.0.0'], [$server->createContext()->implementation->name, $server->createContext()->implementation->version]);
        $this->assertSame([], $server->createContext()->tools()->all());
    }

    public function testResultsAndRequestsAreMutableArrays(): void
    {
        $response = JsonRpcResponse::result(1, ['tools' => []]);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'x']);

        $this->assertSame(['id' => 1, 'result' => ['tools' => []]], $response->content);
        $this->assertSame(['name' => 'x'], $request->params);
        $this->assertFalse((new \ReflectionProperty(JsonRpcResponse::class, 'content'))->isReadOnly());
        $this->assertFalse((new \ReflectionProperty(JsonRpcRequest::class, 'params'))->isReadOnly());
    }

    private function server(LineTransport $transport): EmptyServer
    {
        $server = $this->app?->make(EmptyServer::class, ['transport' => $transport]);
        $this->assertInstanceOf(EmptyServer::class, $server);

        return $server;
    }
}
