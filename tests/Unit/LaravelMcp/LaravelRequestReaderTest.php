<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\LaravelMcp;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\LaravelMcp\Internal\HandshakeClient;
use AgentCat\LaravelMcp\Internal\LaravelRequestReader;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class LaravelRequestReaderTest extends TestCase
{
    private ServerContext $context;

    #[\Override]
    protected function setUp(): void
    {
        $this->context = (new ConformanceServer(new LineTransport()))->createContext();
    }

    #[Group('ac-02-43')]
    public function testTheReaderBuildsTheTreesAndTheToolCall(): void
    {
        $http = new HttpContext('POST', 'http://localhost/mcp', ['x-agentcat-health-run' => 'S']);
        $meta = [Constants::META_PROTOCOL_VERSION_KEY => '2026-07-28', Constants::META_CLIENT_INFO_KEY => ['name' => 'modern', 'version' => '2'], 'progressToken' => 'p1'];
        $request = new JsonRpcRequest(4, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'context' => 'why'], '_meta' => $meta]);

        $context = (new LaravelRequestReader(static fn(): HttpContext => $http, new HandshakeClient()))->read($request, $this->context);

        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{"msg":"hi","context":"why"},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"modern","version":"2"},"progressToken":"p1"}}}', $this->json($context->request));
        $this->assertSame('{"requestId":4,"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientInfo":{"name":"modern","version":"2"},"progressToken":"p1"},"requestInfo":{"headers":{"x-agentcat-health-run":"S"},"url":"http://localhost/mcp"}}', $this->json($context->extra));
        $this->assertSame(['2026-07-28', 'modern', '2', false], [$context->protocolVersion, $context->clientName, $context->clientVersion, $context->continuation]);
        $call = $context->toolCall;
        $this->assertSame('echo', $call->toolName);
        $this->assertSame(['msg' => 'hi', 'context' => 'why'], $call->arguments);
        $this->assertSame($meta, $call->meta);
        $this->assertSame(['x-agentcat-health-run' => 'S'], $call->headers);
        $this->assertSame(['modern', '2', '2026-07-28', null], [$call->clientName, $call->clientVersion, $call->protocolVersion, $call->transportSessionId]);
        $this->assertSame($request, $call->request);
        $this->assertSame($this->context, $call->context);
    }

    #[Group('ac-02-44')]
    public function testWithoutMetaTheClientIsTheHandshakesAndThereIsNoProtocolClaim(): void
    {
        $handshake = new HandshakeClient();
        $handshake->record(['name' => 'desktop', 'version' => '0.9']);
        $request = new JsonRpcRequest('r', 'tools/call', ['name' => 'echo', 'arguments' => []]);

        $context = (new LaravelRequestReader(static fn(): null => null, $handshake))->read($request, $this->context);

        $this->assertSame(['desktop', '0.9', null], [$context->clientName, $context->clientVersion, $context->protocolVersion]);
        $this->assertSame('{"requestId":"r"}', $this->json($context->extra));
        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{}}}', $this->json($context->request));
        $this->assertSame([], $context->toolCall->meta);
        $this->assertSame([], $context->toolCall->headers);
    }

    public function testTheCallsClientInfoBeatsTheHandshake(): void
    {
        $handshake = new HandshakeClient();
        $handshake->record(['name' => 'desktop', 'version' => '0.9']);
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'echo', '_meta' => [Constants::META_CLIENT_INFO_KEY => ['name' => 'modern']]]);

        $context = (new LaravelRequestReader(static fn(): null => null, $handshake))->read($request, $this->context);

        $this->assertSame(['modern', null], [$context->clientName, $context->clientVersion]);
    }

    public function testAnOddNameAndOddArgumentsAreReadAsNone(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => ['echo'], 'arguments' => 'x', '_meta' => []]);

        $context = (new LaravelRequestReader(static fn(): null => null, new HandshakeClient()))->read($request, $this->context);

        $this->assertSame('', $context->toolCall->toolName);
        $this->assertSame([], $context->toolCall->arguments);
        $this->assertSame('{"method":"tools/call","params":{"name":["echo"],"arguments":{},"_meta":{}}}', $this->json($context->request));
        $this->assertSame('{"requestId":1,"_meta":{}}', $this->json($context->extra));
    }

    public function testARoundWithInputResponsesIsAContinuation(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'echo', 'inputResponses' => ['a' => ['ok' => true]]]);

        $context = (new LaravelRequestReader(static fn(): null => null, new HandshakeClient()))->read($request, $this->context);

        $this->assertTrue($context->continuation);
    }

    private function json(mixed $tree): string
    {
        return json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
