<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\RequestReader;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Implementation;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The request data an event records and the hooks see (D34, D35). */
final class RequestContextTest extends TestCase
{
    private TrackingFixture $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-02-44')]
    #[Group('ac-01-81')]
    public function testClientInfoInMetaBeatsTheHandshakeFieldByField(): void
    {
        $harness = $this->tracked();

        $harness->callTool('echo', ['msg' => 'hi'], [Constants::META_CLIENT_INFO_KEY => ['name' => 'meta-client', 'version' => 7]]);
        $harness->callTool('echo', ['msg' => 'hi']);
        $harness->callToolModern('echo', ['msg' => 'hi']);

        $meta = $this->fixture->event(0, of: 3);
        $handshake = $this->fixture->event(1, of: 3);
        $modern = $this->fixture->event(2, of: 3);
        $this->assertSame(['meta-client', null], [$meta->clientName, $meta->clientVersion]);
        $this->assertSame(['harness-client', '1.0.0'], [$handshake->clientName, $handshake->clientVersion]);
        $this->assertSame(['modern-client', '2.0.0'], [$modern->clientName, $modern->clientVersion]);
    }

    #[Group('ac-02-45')]
    #[Group('ac-01-82')]
    public function testTheProtocolTagComesFromTheCallNeverFromTheHandshake(): void
    {
        $harness = $this->tracked();

        // mcp/sdk rejects a handshake call whose claim differs from its MCP-Protocol-Version header.
        $harness->callTool('echo', ['msg' => 'hi'], [Constants::META_PROTOCOL_VERSION_KEY => '2025-06-18']);
        $harness->callTool('echo', ['msg' => 'hi']);
        $harness->callToolModern('echo', ['msg' => 'hi']);

        $claimed = $this->fixture->event(0, of: 3);
        $plain = $this->fixture->event(1, of: 3);
        $modern = $this->fixture->event(2, of: 3);
        $this->assertSame('2025-06-18', $this->tag($claimed, Constants::TAG_PROTOCOL_VERSION));
        $this->assertArrayNotHasKey(Constants::TAG_PROTOCOL_VERSION, $plain->tags ?? [], 'the negotiated version is not a claim');
        $this->assertSame(HttpHarness::MODERN_PROTOCOL_VERSION, $this->tag($modern, Constants::TAG_PROTOCOL_VERSION));
    }

    #[Group('ac-02-46')]
    public function testExtraCarriesTheTransportSessionOfTheHandshakeEraOnly(): void
    {
        $harness = $this->tracked();

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $harness->callToolModern('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $handshake = $this->fixture->event(0, of: 2);
        $modern = $this->fixture->event(1, of: 2);
        $transportSession = $harness->sessionId();
        $this->assertIsString($transportSession);
        $this->assertSame($transportSession, JsonAssert::at($handshake->parameters, 'extra', 'sessionId'));
        $this->assertNotSame($transportSession, $handshake->sessionId);
        $this->assertObjectNotHasProperty('sessionId', $this->extra($modern));
    }

    public function testExtraHasTheD34KeysInOrderAndNoRequestInfoOnTheCommandLine(): void
    {
        $harness = $this->tracked();

        $harness->callTool('echo', ['msg' => 'hi']);
        $harness->callTool('echo', ['msg' => 'hi'], ['progressToken' => 'p1']);
        $harness->callToolModern('echo', ['msg' => 'hi']);

        $plain = $this->fixture->event(0, of: 3);
        $meta = $this->fixture->event(1, of: 3);
        $modern = $this->fixture->event(2, of: 3);
        $this->assertSame(['sessionId', 'requestId'], $this->keys($this->extra($plain)));
        $this->assertIsInt(JsonAssert::at($plain->parameters, 'extra', 'requestId'));
        $this->assertSame(['sessionId', 'requestId', '_meta'], $this->keys($this->extra($meta)));
        $this->assertSame('{"progressToken":"p1"}', $this->json(JsonAssert::at($meta->parameters, 'extra', '_meta')));
        $this->assertSame('p1', JsonAssert::at($meta->parameters, 'request', 'params', '_meta', 'progressToken'));
        $this->assertSame(['requestId', '_meta'], $this->keys($this->extra($modern)));
    }

    public function testTheReaderRecordsTheHttpRequestForTheEventAndTheHooks(): void
    {
        $reader = new RequestReader(static fn(): HttpContext => new HttpContext('POST', 'http://x/mcp', ['x-agentcat-health-run' => 'S']));
        $session = new Session(new InMemorySessionStore());
        $request = (new CallToolRequest('echo', ['msg' => 'hi']))->withId(9)->withMeta(['progressToken' => 'p1']);

        $context = $reader->read($request, $session);

        $this->assertSame(['sessionId', 'requestId', '_meta', 'requestInfo'], $this->keys($context->extra));
        $this->assertSame((string) $session->getId(), $context->extra->sessionId);
        $this->assertSame(9, $context->extra->requestId);
        $this->assertSame('{"headers":{"x-agentcat-health-run":"S"},"url":"http://x/mcp"}', $this->json($context->extra->requestInfo));
        $this->assertSame(['x-agentcat-health-run' => 'S'], $context->toolCall->headers);
    }

    public function testTheReaderBuildsTheRequestTreeAndTheToolCall(): void
    {
        $session = new Session(new InMemorySessionStore());
        $session->set('client_info', ['name' => 'harness-client', 'version' => '1.0.0']);
        $session->set('protocol_version', '2025-06-18');
        $request = (new CallToolRequest('echo', ['msg' => 'hi', 'context' => 'why']))->withId('r1')->withMeta(['progressToken' => 'p1']);

        $context = (new RequestReader(static fn(): ?HttpContext => null))->read($request, $session);

        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{"msg":"hi","context":"why"},"_meta":{"progressToken":"p1"}}}', $this->json($context->request));
        $call = $context->toolCall;
        $this->assertSame('echo', $call->toolName);
        $this->assertSame(['msg' => 'hi', 'context' => 'why'], $call->arguments);
        $this->assertSame(['progressToken' => 'p1'], $call->meta);
        $this->assertSame([], $call->headers);
        $this->assertSame(['harness-client', '1.0.0'], [$call->clientName, $call->clientVersion]);
        $this->assertSame(['harness-client', '1.0.0'], [$context->clientName, $context->clientVersion]);
        $this->assertNull($call->protocolVersion, 'the negotiated version is not a claim');
        $this->assertNull($context->protocolVersion);
        $this->assertSame((string) $session->getId(), $call->transportSessionId);
        $this->assertSame($request, $call->request);
        $this->assertSame($session, $call->context);
        $this->assertFalse($context->continuation);
    }

    public function testAnEmptyMetaIsRecordedAsAnEmptyObject(): void
    {
        $harness = $this->tracked();

        $harness->callTool('echo', ['msg' => 'hi'], []);

        $parameters = $this->fixture->event()->parameters;
        $this->assertSame('{}', $this->json(JsonAssert::at($parameters, 'request', 'params', '_meta')));
        $this->assertSame('{}', $this->json(JsonAssert::at($parameters, 'extra', '_meta')));
    }

    public function testTheReaderKeepsIntegerArgumentKeysAndEmptyArgumentsAsAnObject(): void
    {
        $reader = new RequestReader(static fn(): ?HttpContext => null);
        $session = new Session(new InMemorySessionStore());

        $listed = $reader->read(CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['zero', 'one']]]), $session);
        $empty = $reader->read((new CallToolRequest('echo', []))->withId(2), $session);
        $emptyMeta = $reader->read((new CallToolRequest('echo', []))->withId(3)->withMeta([]), $session);

        $this->assertSame('{"0":"zero","1":"one"}', $this->json(JsonAssert::at($listed->request, 'params', 'arguments')), 'N8');
        $this->assertSame(['zero', 'one'], $listed->toolCall->arguments);
        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{}}}', $this->json($empty->request));
        $this->assertSame(['sessionId', 'requestId'], $this->keys($empty->extra), 'no _meta when none was sent');
        $this->assertSame('{"method":"tools/call","params":{"name":"echo","arguments":{},"_meta":{}}}', $this->json($emptyMeta->request));
    }

    public function testTheModernEraReadsTheRequestMeta(): void
    {
        $session = new Session(new InMemorySessionStore());
        $session->set(RequestMeta::class, new RequestMeta('2026-07-28', new ClientCapabilities(), new Implementation('modern-client', '2.0.0')));
        $session->set('protocol_version', '2026-07-28');
        $request = (new CallToolRequest('echo', []))->withId(3)->withMeta([
            Constants::META_PROTOCOL_VERSION_KEY => '2099-01-01',
            Constants::META_CLIENT_INFO_KEY => ['name' => 'meta-client', 'version' => '9'],
        ]);

        $context = (new RequestReader(static fn(): ?HttpContext => null))->read($request, $session);

        $this->assertSame('2026-07-28', $context->protocolVersion);
        $this->assertSame('2026-07-28', $context->toolCall->protocolVersion);
        $this->assertSame(['modern-client', '2.0.0'], [$context->clientName, $context->clientVersion]);
        $this->assertNull($context->toolCall->transportSessionId);
        $this->assertSame(['requestId', '_meta'], $this->keys($context->extra));
    }

    public function testAProtocolClaimInMetaMustBeANonEmptyString(): void
    {
        $reader = new RequestReader(static fn(): ?HttpContext => null);
        $session = new Session(new InMemorySessionStore());
        $read = static fn(mixed $claim): ?string => $reader->read(
            (new CallToolRequest('echo', []))->withId(1)->withMeta([Constants::META_PROTOCOL_VERSION_KEY => $claim]),
            $session,
        )->protocolVersion;

        $this->assertSame('2025-06-18', $read('2025-06-18'));
        $this->assertNull($read(''));
        $this->assertNull($read(20250618));
    }

    /** `authInfo` exists when the session holds an access token (mcp/sdk main), read by duck typing. */
    public function testExtraCarriesTheAccessTokenAsAuthInfo(): void
    {
        $session = new Session(new InMemorySessionStore());
        $session->set('Mcp\Server\Authorization\AccessToken', new class {
            /** @return list<string> */
            public function getScopes(): array
            {
                return ['read', 'write'];
            }

            /** @return array<string, mixed> */
            public function getClaims(): array
            {
                return ['sub' => 'u1', 'aud' => ['mcp']];
            }
        });
        $reader = new RequestReader(static fn(): HttpContext => new HttpContext('POST', null, []));

        $context = $reader->read((new CallToolRequest('echo', []))->withId(1), $session);

        $this->assertSame(['sessionId', 'requestId', 'requestInfo', 'authInfo'], $this->keys($context->extra));
        $this->assertSame('{"scopes":["read","write"],"claims":{"sub":"u1","aud":["mcp"]}}', $this->json($context->extra->authInfo));
        $this->assertSame('{"headers":{}}', $this->json($context->extra->requestInfo));
    }

    public function testAnAccessTokenWithoutClaimsIsAnEmptyObjectAndItsScopesAList(): void
    {
        $session = new Session(new InMemorySessionStore());
        $session->set('Mcp\Server\Authorization\AccessToken', new class {
            /** @return array<int, string> */
            public function getScopes(): array
            {
                return [2 => 'read'];
            }

            /** @return array<string, mixed> */
            public function getClaims(): array
            {
                return [];
            }
        });

        $context = (new RequestReader(static fn(): ?HttpContext => null))->read((new CallToolRequest('echo', []))->withId(1), $session);

        $this->assertSame('{"scopes":["read"],"claims":{}}', $this->json($context->extra->authInfo));
    }

    public function testNoAuthInfoWithoutAnAccessToken(): void
    {
        $reader = new RequestReader(static fn(): ?HttpContext => null);
        $request = (new CallToolRequest('echo', []))->withId(1);
        $none = new Session(new InMemorySessionStore());
        $notAToken = new Session(new InMemorySessionStore());
        $notAToken->set('Mcp\Server\Authorization\AccessToken', new class {
            /** @return list<string> */
            public function getScopes(): array
            {
                return ['read'];
            }
        });

        $this->assertObjectNotHasProperty('authInfo', $reader->read($request, $none)->extra);
        $this->assertObjectNotHasProperty('authInfo', $reader->read($request, $notAToken)->extra);
    }

    #[Group('ac-02-35')]
    public function testARoundWithInputResponsesIsAContinuation(): void
    {
        $reader = new RequestReader(static fn(): ?HttpContext => null);
        $request = (new CallToolRequest('echo', []))->withId(1);
        $retry = new Session(new InMemorySessionStore());
        $retry->set(InputContext::class, new InputContext());

        $this->assertTrue($reader->read($request, $retry)->continuation);
        $this->assertFalse($reader->read($request, new Session(new InMemorySessionStore()))->continuation);
    }

    private function tracked(): HttpHarness
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        return $harness;
    }

    private function extra(Event $event): \stdClass
    {
        $extra = JsonAssert::at($event->parameters, 'extra');
        $this->assertInstanceOf(\stdClass::class, $extra);

        return $extra;
    }

    private function tag(Event $event, string $key): ?string
    {
        return $event->tags[$key] ?? null;
    }

    /** @return list<string> */
    private function keys(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
