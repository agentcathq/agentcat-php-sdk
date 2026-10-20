<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\CustomCallServer;
use AgentCat\Tests\Support\LaravelMcp\CustomCallTool;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelStdioHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\LaravelMcp\ListingServer;
use AgentCat\Tests\Support\LaravelMcp\PagedGetMoreToolsServer;
use AgentCat\Tests\Support\LaravelMcp\PagedOwnServer;
use AgentCat\Tests\Support\LaravelMcp\RecordingCallServer;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use AgentCat\Tests\Support\LaravelMcp\RequestEchoServer;
use AgentCat\Tests\Support\LaravelMcp\StreamingServer;
use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\StructuredTool;
use AgentCat\Tests\Support\ListingLines;
use AgentCat\Tests\Support\ToolCalls;
use AgentCat\ToolCall;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class CallToolTest extends LaravelTestCase
{
    private const string SESSION = 'ses_bbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private const string CANNED = 'Unfortunately, we have shown you the full tool list. We have noted your feedback and will work to improve the tool list in the future.';

    #[Group('ac-02-16')]
    #[Group('ac-02-47')]
    public function testTheToolGetsItsOwnArgumentsAndTheEventRecordsTheCall(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame([['msg' => 'hi']], ToolCalls::of('echo'));
        $this->assertSame('echo: hi', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $event = $fixture->event();
        $this->assertSame('echo', $event->resourceName);
        $this->assertSame(Constants::TOOLS_CALL_EVENT_TYPE, $event->eventType);
        $this->assertSame('why', $event->userIntent);
        $this->assertSame(self::SESSION, $event->sessionId);
        $this->assertSame('supplied', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame('2026-07-28', $event->tags[Constants::TAG_PROTOCOL_VERSION] ?? null);
        $this->assertSame(['conformance-server', '2.0.0'], [$event->serverName, $event->serverVersion]);
        $this->assertSame(['modern-client', '2.0.0'], [$event->clientName, $event->clientVersion]);
        $this->assertSame(['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION], (array) JsonAssert::at($event->parameters, 'request', 'params', 'arguments'));
        $this->assertSame('echo', JsonAssert::at($event->parameters, 'request', 'params', 'name'));
        $this->assertSame('tools/call', JsonAssert::at($event->parameters, 'request', 'method'));
        $this->assertSame(1, JsonAssert::at($event->parameters, 'extra', 'requestId'));
        $this->assertSame(['name' => 'modern-client', 'version' => '2.0.0'], (array) JsonAssert::at($event->parameters, 'extra', '_meta', Constants::META_CLIENT_INFO_KEY));
    }

    public function testTheToolsRequestObjectNeverSeesTheInjectedArguments(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(RequestEchoServer::class, $fixture->tracker());

        $harness->callTool('request-echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => 'start']);

        $this->assertSame([['request' => ['msg' => 'hi'], 'container' => ['msg' => 'hi']]], ToolCalls::of('request-echo'));
    }

    #[Group('ac-02-20')]
    public function testAFreshServerRebuildsTheRegistriesOnItsFirstCall(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => 'start']);

        $this->assertContains('Rebuilt injection registries on demand (tools/call before tools/list on this instance)', $fixture->lines());
        $this->assertSame([['msg' => 'hi']], ToolCalls::of('echo'));
    }

    public function testAServerThatListsWithoutAMethodClassStripsHeuristically(): void
    {
        $fixture = new LaravelTrackingFixture();
        $transport = new LineTransport();
        $server = new class ($transport) extends Server {
            public function __construct(Transport $transport)
            {
                parent::__construct($transport);
                $this->tools = [EchoTool::class];
                // @phpstan-ignore argument.type (a container key, as another package could bind)
                $this->addMethod('tools/list', 'customer.list');
            }
        };
        $fixture->track($server);
        $server->start();

        $transport->deliver(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'agent_id' => 'a', 'session_id' => 'start']]]);

        $this->assertSame([['msg' => 'hi']], ToolCalls::of('echo')); // the heuristic strip: no listing names echo
        $this->assertSame([], $fixture->lines(), 'no rebuild without a listing');
        $this->assertCount(1, $fixture->events());
    }

    /** @return iterable<string, array{\Closure(LaravelHarness): \stdClass}> */
    public static function eras(): iterable
    {
        yield '2026-07-28' => [static fn(LaravelHarness $harness): \stdClass => $harness->callTool('own', ['session_id' => 'theirs', 'context' => 'why'])];
        yield 'legacy' => [static fn(LaravelHarness $harness): \stdClass => $harness->request('tools/call', ['name' => 'own', 'arguments' => ['session_id' => 'theirs', 'context' => 'why']])];
    }

    /**
     * D66 (B10 fixed): a new server per request, as under PHP-FPM, rebuilds from page 1 to the page that names the
     * tool, so `own`, alone on page 2, is stripped exactly and keeps its own `session_id`.
     *
     * @param \Closure(LaravelHarness): \stdClass $call
     */
    #[DataProvider('eras')]
    #[Group('ac-02-19')]
    #[Group('ac-02-20')]
    public function testAToolOnTheSecondPageKeepsItsOwnSessionId(\Closure $call): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(PagedOwnServer::class, $fixture->tracker());

        $answer = $call($harness);

        $this->assertSame([['session_id' => 'theirs']], ToolCalls::of('own'));
        $this->assertEquals([(object) ['type' => 'text', 'text' => 'ok']], JsonAssert::at($answer, 'result', 'content')); // no "unrecognized" block
        $event = $fixture->event();
        $this->assertSame('', $event->sessionId);
        $this->assertSame('foreign', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame('why', $event->userIntent);
        $this->assertSame([ListingLines::ownSessionId('own'), self::REBUILT], $fixture->lines());
    }

    /** D66 (B10 fixed): on a long-running server each listed page merges into the registries, so no call rebuilds. */
    #[Group('ac-02-8')]
    public function testListedPagesMergeOnALongRunningServer(): void
    {
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(PagedOwnServer::class, $fixture->tracker());
        $first = JsonAssert::at($stdio->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass()]), 'result');
        $cursor = JsonAssert::at($first, 'nextCursor');
        $this->assertIsString($cursor);
        $second = JsonAssert::at($stdio->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['cursor' => $cursor]]), 'result');

        $stdio->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'agent_id' => 'a1', 'session_id' => 'start']]]);
        $stdio->send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'own', 'arguments' => ['session_id' => 'theirs']]]);

        $this->assertSame(['echo', ...array_map(static fn(int $i): string => sprintf('t%02d', $i), range(1, 14))], $this->names($first));
        $this->assertSame(['own', GetMoreTools::NAME], $this->names($second));
        $this->assertSame([['msg' => 'hi', 'agent_id' => 'a1']], ToolCalls::of('echo'), 'agent tracking is off: agent_id is not ours');
        $this->assertSame([['session_id' => 'theirs']], ToolCalls::of('own'));
        $this->assertSame([ListingLines::ownSessionId('own')], $fixture->lines(), 'both tools were listed: no rebuild');
    }

    /**
     * D66 (B10 fixed): the rebuild stops at the first page that names the tool, or at a page it cannot read. No page
     * that the rebuild could read names `echo`, and page 2 fails. Page 1 is kept, one line says where the rebuild
     * stopped, and the call is served with the heuristic strip.
     */
    #[Group('ac-02-21')]
    public function testASecondPageThatFailsLeavesTheCallServed(): void
    {
        $list = new RecordingMethod(answer: static fn(JsonRpcRequest $request): JsonRpcResponse => isset($request->params['cursor'])
            ? throw new \RuntimeException('page down')
            : JsonRpcResponse::result($request->id, ['tools' => [['name' => 't01', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]], 'nextCursor' => 'p2']));
        $this->app?->instance(RecordingMethod::class, $list);
        $fixture = new LaravelTrackingFixture();

        $answer = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->callTool('echo', ['msg' => 'hi', 'agent_id' => 'a1', 'session_id' => 'start', 'context' => 'why']);

        $this->assertSame('echo: hi', JsonAssert::at($answer, 'result', 'content', 1, 'text'));
        $this->assertSame([['msg' => 'hi']], ToolCalls::of('echo'));
        $this->assertSame([null, 'p2'], array_map(static fn(JsonRpcRequest $request): mixed => $request->params['cursor'] ?? null, $list->requests));
        $this->assertSame('minted', $fixture->event()->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame(['Warning: registry rebuild-on-demand stopped after page 1 - RuntimeException: page down'], $fixture->lines());
    }

    /**
     * D66: a get_more_tools call rebuilds like any other tool. A new server per request (PHP-FPM), no listing: the
     * rebuild finds the customer's own get_more_tools on page 2. AgentCat answers the call (report-missing shadows the
     * customer's tool), and the `session_id` that tool declares stays the customer's: no session is read from it and
     * no "unrecognized" block is added.
     */
    #[Group('ac-02-19')]
    #[Group('ac-02-14')]
    public function testAShadowedCustomerGetMoreToolsKeepsItsOwnSessionId(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(PagedGetMoreToolsServer::class, $fixture->tracker());

        $answer = $harness->callTool(GetMoreTools::NAME, ['session_id' => 'theirs', 'context' => 'need X']);

        $this->assertEquals([(object) ['type' => 'text', 'text' => self::CANNED]], JsonAssert::at($answer, 'result', 'content'));
        $this->assertSame([], ToolCalls::of(GetMoreTools::NAME), 'AgentCat answers its calls');
        $event = $fixture->event();
        $this->assertSame('', $event->sessionId);
        $this->assertSame('foreign', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame('need X', $event->userIntent);
        $this->assertSame(ListingLines::shadowedCall(6), $fixture->lines());
    }

    /** D66: with report-missing off, the customer's own get_more_tools on page 2 is found and gets its `session_id`. */
    #[Group('ac-02-19')]
    #[Group('ac-02-41')]
    public function testWithReportMissingOffACustomerGetMoreToolsOnPageTwoGetsItsOwnSessionId(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(PagedGetMoreToolsServer::class, $fixture->tracker(new Options(enableReportMissing: false)));

        $answer = $harness->callTool(GetMoreTools::NAME, ['session_id' => 'theirs']);

        $this->assertSame('mine', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertSame([['session_id' => 'theirs']], ToolCalls::of(GetMoreTools::NAME));
        $this->assertSame('foreign', $fixture->event()->tags[Constants::TAG_SESSION_SOURCE] ?? null);
    }

    #[Group('ac-02-24')]
    #[Group('ac-05-E5')]
    public function testAThrownToolExceptionIsRecordedAsItself(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $answer = $harness->callTool('boom', ['session_id' => 'start', 'context' => 'why']);

        $this->assertTrue(JsonAssert::at($answer, 'result', 'isError'));
        $this->assertStringContainsString('An internal server error occurred.', json_encode(JsonAssert::at($answer, 'result', 'content'), JSON_THROW_ON_ERROR));
        $event = $fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('RuntimeException', JsonAssert::at($event->error, 'type'));
        $this->assertSame('tool boom', JsonAssert::at($event->error, 'message'));
        $this->assertSame('LogicException', JsonAssert::at($event->error, 'chained_errors', 0, 'type'));
    }

    #[Group('ac-02-26')]
    public function testAnUnknownToolIsOneErrorEventAndTheLibrarysAnswer(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());
        $twin = (new LaravelHarness(ConformanceServer::class))->callTool('nope', ['session_id' => 'start']);

        $answer = $harness->callTool('nope', ['session_id' => 'start']);

        $this->assertSame(-32602, JsonAssert::at($answer, 'error', 'code'));
        $this->assertSame('Tool [nope] not found.', JsonAssert::at($answer, 'error', 'message'));
        $this->assertEquals($twin, $answer);
        $event = $fixture->event();
        $this->assertSame('nope', $event->resourceName);
        $this->assertTrue($event->isError);
        $this->assertSame('Tool [nope] not found.', JsonAssert::at($event->error, 'message'));
    }

    #[Group('ac-02-30')]
    #[Group('ac-02-33')]
    public function testTheFirstCallGetsItsSessionInATextBlock(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $answer = $harness->callTool('refuses', ['context' => 'why']);

        $event = $fixture->event();
        $this->assertSame(
            Constants::MINT_BACK_HEADER_ISSUED . "\nsession_id: " . $event->sessionId . "\n" . Constants::MINT_BACK_ISSUED_BODY,
            JsonAssert::at($answer, 'result', 'content', 0, 'text'),
        );
        $this->assertSame('minted', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertTrue(JsonAssert::at($answer, 'result', 'isError'));
        $this->assertSame('not allowed', JsonAssert::at($event->error, 'message'));
    }

    #[Group('ac-02-36')]
    #[Group('ac-02-38')]
    public function testStructuredContentGetsTheSessionMirrorFirst(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker()); // the call's own rebuild finds the outputSchema

        $answer = $harness->callTool('structured', ['session_id' => 'start', 'context' => 'why']);

        $structured = JsonAssert::at($answer, 'result', 'structuredContent');
        $this->assertInstanceOf(\stdClass::class, $structured);
        $this->assertSame([Constants::MCP_SESSION_KEY, 'echoed'], array_keys(get_object_vars($structured)));
        $this->assertSame('issued', JsonAssert::at($structured, Constants::MCP_SESSION_KEY, 'status'));
        $this->assertEquals((object) ['echoed' => 'x'], JsonAssert::at($fixture->event()->response, 'structuredContent'));
    }

    #[Group('ac-02-40')]
    public function testGetMoreToolsIsAnsweredByAgentCat(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $answer = $harness->callTool(GetMoreTools::NAME, ['context' => 'need X', 'session_id' => self::SESSION]);

        $content = JsonAssert::at($answer, 'result', 'content');
        $this->assertIsList($content);
        $this->assertSame('Unfortunately, we have shown you the full tool list. We have noted your feedback and will work to improve the tool list in the future.', JsonAssert::at(end($content), 'text'));
        $this->assertSame('need X', $fixture->event()->userIntent);
        $this->assertContains('Missing tool reported (context length: 6)', $fixture->lines());
    }

    #[Group('ac-02-43')]
    public function testHeadersComeFromTheServersOwnRequest(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'], headers: ['X-AgentCat-Health-Run' => 'S']);

        $info = JsonAssert::at($fixture->event()->parameters, 'extra', 'requestInfo');
        $this->assertSame('S', JsonAssert::at($info, 'headers', 'x-agentcat-health-run'));
        $this->assertSame('http://localhost/mcp/conformance', JsonAssert::at($info, 'url'));
    }

    /** Task 5 review minor 2: the client controls the headers; Symfony refuses an invalid method override. */
    public function testAnInvalidMethodOverrideCostsTheHttpContextOnly(): void
    {
        $fixture = new LaravelTrackingFixture();
        $headers = ['X-HTTP-Method-Override' => 'NOT A METHOD'];
        $twin = (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION], headers: $headers);

        $answer = (new LaravelHarness(ConformanceServer::class, $fixture->tracker()))->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION], headers: $headers);

        $this->assertEquals($twin, $answer);
        $extra = JsonAssert::at($fixture->event()->parameters, 'extra');
        $this->assertInstanceOf(\stdClass::class, $extra);
        $this->assertObjectNotHasProperty('requestInfo', $extra);
        $this->assertNotContains(LaravelMcpAdapter::COMPATIBILITY_LINE, $fixture->lines());
    }

    /** The URL is built like the SAPI reading's (Task 6 review): an invalid `Host` header is recorded as it was sent. */
    #[Group('ac-02-43')]
    public function testAnInvalidHostHeaderIsRecordedAsSent(): void
    {
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION], headers: ['Host' => 'bad host!']);

        $answer = (new LaravelHarness(ConformanceServer::class, $fixture->tracker()))->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION], headers: ['Host' => 'bad host!']);

        $this->assertEquals($twin, $answer);
        $info = JsonAssert::at($fixture->event()->parameters, 'extra', 'requestInfo');
        $this->assertSame('http://bad host!/mcp/conformance', JsonAssert::at($info, 'url'));
        $this->assertSame('bad host!', JsonAssert::at($info, 'headers', 'host'));
        $this->assertNotContains(LaravelMcpAdapter::COMPATIBILITY_LINE, $fixture->lines());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function callsWithoutAToolName(): iterable
    {
        yield 'no name' => [['arguments' => ['msg' => 'hi']]];
        yield 'a number' => [['name' => 7, 'arguments' => ['msg' => 'hi']]];
    }

    /**
     * TypeScript and mcp/sdk reject such a call before any handler, so none is recorded (D15, Task 5 review minor 4).
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('callsWithoutAToolName')]
    public function testACallWithoutAToolNameIsLaravelsAnswerAndNoEvent(array $params): void
    {
        $fixture = new LaravelTrackingFixture();
        $params['_meta'] = LaravelHarness::meta([], null);
        $twin = (new LaravelHarness(ConformanceServer::class))->request('tools/call', $params);

        $answer = (new LaravelHarness(ConformanceServer::class, $fixture->tracker()))->request('tools/call', $params);

        $this->assertEquals($twin, $answer);
        $this->assertObjectHasProperty('error', $answer);
        $this->assertSame([], $fixture->events());
        $this->assertSame([], $fixture->lines());
    }

    public function testACustomHandlersAnswerToACallWithoutAToolNameIsNoEvent(): void
    {
        $this->answer(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, ['content' => [['type' => 'text', 'text' => 'nameless']]]));
        $params = ['arguments' => ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], null)];
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(RecordingCallServer::class))->request('tools/call', $params);

        $answer = (new LaravelHarness(RecordingCallServer::class, $fixture->tracker()))->request('tools/call', $params);

        $this->assertEquals($twin, $answer);
        $this->assertSame('nameless', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertSame([], $fixture->events());
    }

    #[Group('ac-02-44')]
    public function testALegacyStdioClientIsKnownFromItsInitialize(): void
    {
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(ConformanceServer::class, $fixture->tracker());
        $stdio->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'desktop', 'version' => '0.9']]]);

        $stdio->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => 'start']]]);

        $event = $fixture->event();
        $this->assertSame(['desktop', '0.9'], [$event->clientName, $event->clientVersion]);
        $this->assertArrayNotHasKey(Constants::TAG_PROTOCOL_VERSION, $event->tags ?? []);
        $extra = JsonAssert::at($event->parameters, 'extra');
        $this->assertInstanceOf(\stdClass::class, $extra);
        $this->assertObjectNotHasProperty('requestInfo', $extra, 'stdio has no HTTP request');
    }

    #[Group('ac-02-52')]
    public function testTracingOffPassesTheCallUnchanged(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker(new Options(enableTracing: false)));

        $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why']);

        $this->assertSame([['msg' => 'hi', 'context' => 'why']], ToolCalls::of('echo')); // D15, B06: unstripped
        $this->assertSame([], $fixture->events());
    }

    public function testACustomToolsCallHandlerIsWrapped(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(CustomCallServer::class, $fixture->tracker());

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame(1, CustomCallTool::$calls);
        $this->assertSame([['msg' => 'hi']], ToolCalls::of('echo'));
        $this->assertSame('echo: hi', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertCount(1, $fixture->events());
    }

    /** Task 4 review M2: AgentCat builds the handler after laravel/mcp binds `mcp.request`, and after the strip. */
    public function testACustomHandlersConstructorRequestSeesTheStrippedArguments(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(CustomCallServer::class, $fixture->tracker());

        $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame([['msg' => 'hi']], CustomCallTool::$constructed);
    }

    /** A container binding of `CallTool` answers, as without AgentCat; AgentCat then keeps no tool exception. */
    public function testACallToolBindingOfTheContainerStillAnswers(): void
    {
        $calls = new \ArrayObject();
        $this->app?->bind(CallTool::class, static fn(): CallTool => new class ($calls) extends CallTool {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private readonly \ArrayObject $calls) {}

            #[\Override]
            public function handle(JsonRpcRequest $request, ServerContext $context): \Generator|JsonRpcResponse
            {
                $this->calls->append('handle');

                return parent::handle($request, $context);
            }
        });
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $answer = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertSame(['handle'], $calls->getArrayCopy());
        $this->assertTrue(JsonAssert::at($answer, 'result', 'isError'));
        $event = $fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('An internal server error occurred.', JsonAssert::at($event->error, 'message'), 'the result text: AgentCat keeps no exception for a handler it did not build');
        $error = $event->error;
        $this->assertInstanceOf(\stdClass::class, $error);
        $this->assertObjectNotHasProperty('type', $error);
    }

    /**
     * M4: a customer's decorator of `CallTool` from `extend()`, here an authorization check, runs on a tracked server
     * as without AgentCat: the container builds the handler at each call, and AgentCat answers with what it built.
     */
    public function testAnExtendDecoratorOfCallToolStillRuns(): void
    {
        $checks = new \ArrayObject();
        $this->app?->extend(CallTool::class, static fn(CallTool $tool): Method => new readonly class ($tool, $checks) implements Method {
            /** @param \ArrayObject<int, string> $checks */
            public function __construct(private CallTool $inner, private \ArrayObject $checks) {}

            #[\Override]
            public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
            {
                $this->checks->append((string) $request->get('name'));

                return $request->get('name') === 'boom' ? JsonRpcResponse::error($request->id, -32001, 'Forbidden.') : $this->inner->handle($request, $context);
            }
        });
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $allowed = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $refused = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertSame(['echo', 'boom'], $checks->getArrayCopy());
        $this->assertSame('echo: hi', JsonAssert::at($allowed, 'result', 'content', 0, 'text'));
        $this->assertSame('Forbidden.', JsonAssert::at($refused, 'error', 'message'));
        $events = $fixture->events();
        $this->assertCount(2, $events);
        $this->assertNotTrue($events[0]->isError);
        $this->assertTrue($events[1]->isError);
        $this->assertSame('Forbidden.', JsonAssert::at($events[1]->error, 'message'));
        $this->assertSame([['msg' => 'hi']], ToolCalls::of('echo'), 'the decorator passed the stripped arguments on');
    }

    /**
     * M4: a `resolving()` callback of `CallTool` runs at each call, as without AgentCat; the container still builds
     * laravel/mcp's own `CallTool`, so AgentCat keeps the tool's exception.
     */
    public function testAResolvingCallbackOfCallToolRunsAndTheToolExceptionIsKept(): void
    {
        $resolved = new \ArrayObject();
        $this->app?->resolving(CallTool::class, static function (CallTool $tool) use ($resolved): void {
            $resolved->append($tool::class);
        });
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $answer = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertSame([CallTool::class, CallTool::class], $resolved->getArrayCopy());
        $this->assertTrue(JsonAssert::at($answer, 'result', 'isError'));
        $events = $fixture->events();
        $this->assertCount(2, $events);
        $this->assertSame('RuntimeException', JsonAssert::at($events[1]->error, 'type'));
        $this->assertSame('tool boom', JsonAssert::at($events[1]->error, 'message'));
    }

    public function testAJsonRpcErrorFromACustomHandlerIsOneErrorEventAndPassesUnchanged(): void
    {
        $this->answer(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::error($request->id, -32602, 'bad arguments'));
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(RecordingCallServer::class))->callTool('echo', ['session_id' => 'start']);

        $answer = (new LaravelHarness(RecordingCallServer::class, $fixture->tracker()))->callTool('echo', ['session_id' => 'start']);

        $this->assertEquals($twin, $answer);
        $event = $fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('bad arguments', JsonAssert::at($event->error, 'message'));
    }

    #[MaximumDuration(2000)]
    public function testAStreamedAnswerIsRecordedWhenItEnds(): void
    {
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(StreamingServer::class, $fixture->tracker());

        $stdio->send(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'stream', 'arguments' => ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], null)]]);

        $lines = $stdio->lines();
        $this->assertCount(3, $lines); // two progress notifications, then the result
        $this->assertSame('notifications/progress', JsonAssert::at($lines[0], 'method'));
        $this->assertSame(1, JsonAssert::at($lines[0], 'params', 'progress'));
        $this->assertSame('notifications/progress', JsonAssert::at($lines[1], 'method'));
        $this->assertSame(2, JsonAssert::at($lines[1], 'params', 'progress'));
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $this->text(JsonAssert::at($lines[2], 'result', 'content', 0, 'text')));
        $content = JsonAssert::at($lines[2], 'result', 'content');
        $this->assertIsList($content);
        $this->assertSame('done', JsonAssert::at(end($content), 'text'));
        $this->assertSame(7, JsonAssert::at($lines[2], 'id'));
        $event = $fixture->event();
        $this->assertGreaterThanOrEqual(100, $event->duration); // the stream sleeps 2 × 50 ms
        $this->assertSame([[]], ToolCalls::of('stream'));
    }

    /**
     * Over HTTP laravel/mcp sends a stream from `StreamedResponse::sendContent()`, after `Server::handle()` returned
     * (Review Focus 5): the SSE bytes are the library's, the event comes from the final result, and the hooks run later
     * (D04).
     */
    #[Group('ac-02-43')]
    #[Group('ac-05-E5')]
    #[MaximumDuration(2000)]
    public function testAStreamedAnswerOverHttpIsTheLibrarysAndRecordedWhenItEnds(): void
    {
        $hooks = new \ArrayObject();
        $fixture = new LaravelTrackingFixture();
        $twin = $this->httpStream(null, 'stream');

        $tracked = $this->httpStream($fixture->tracker(new Options(identify: static function (ToolCall $call) use ($hooks): Identity {
            $hooks->append($call->headers['x-one'] ?? null);

            return new Identity('u1');
        })), 'stream');

        $this->assertSame([], $hooks->getArrayCopy(), 'no hook ran during the request');
        $this->assertSame($twin, $tracked);
        $this->assertSame(3, substr_count($tracked, 'data: '));
        $event = $fixture->event();
        $this->assertSame(['a'], $hooks->getArrayCopy());
        $this->assertGreaterThanOrEqual(100, $event->duration); // the stream sleeps 2 × 50 ms
        $this->assertSame('u1', $event->identifyActorGivenId);
        $this->assertSame('a', JsonAssert::at($event->parameters, 'extra', 'requestInfo', 'headers', 'x-one'));
        $failing = new LaravelTrackingFixture();
        $this->assertSame($this->httpStream(null, 'stream-fails'), $this->httpStream($failing->tracker(), 'stream-fails'));
        $this->assertSame('DomainException', JsonAssert::at($failing->event()->error, 'type'));
    }

    /** A stream the client abandons publishes nothing (plan E1 header conflict 6). */
    public function testAStreamTheClientAbandonsPublishesNothing(): void
    {
        $fixture = new LaravelTrackingFixture();
        $server = $this->app?->make(StreamingServer::class, ['transport' => new LineTransport()]);
        $this->assertInstanceOf(StreamingServer::class, $server);
        $fixture->track($server);
        $stream = $server->answer(new JsonRpcRequest(3, 'tools/call', ['name' => 'stream', 'arguments' => ['session_id' => 'start']]));
        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertInstanceOf(JsonRpcResponse::class, $stream->current()); // the first progress notification

        unset($stream);
        gc_collect_cycles();

        $this->assertSame([], $fixture->events());
        $this->assertSame([[]], ToolCalls::of('stream'));
        $this->assertFalse($this->app?->bound('mcp.request'));
    }

    #[Group('ac-05-E5')]
    public function testAStreamThatThrowsIsOneErrorEvent(): void
    {
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(StreamingServer::class, $fixture->tracker());

        $stdio->send(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'stream-fails', 'arguments' => ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], null)]]);

        $event = $fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('DomainException', JsonAssert::at($event->error, 'type'));
        $this->assertSame('stream broke', JsonAssert::at($event->error, 'message'));
        $this->assertTrue(JsonAssert::at($stdio->lines()[1] ?? null, 'result', 'isError'));
    }

    /** Controller ruling P9: a handler's own stream that throws midway reaches `later()`'s catch. */
    #[Group('ac-05-E5')]
    public function testARawStreamThatThrowsMidwayIsOneErrorEventAndTheLibrarysError(): void
    {
        $this->answer(static function (): \Generator {
            yield JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);

            throw new \DomainException('raw stream broke');
        });
        $message = ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], null)]];
        $twin = new LaravelStdioHarness(RecordingCallServer::class);
        $twin->send($message);
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(RecordingCallServer::class, $fixture->tracker());

        $stdio->send($message);

        $this->assertCount(2, $stdio->lines());
        $this->assertEquals($twin->lines(), $stdio->lines());
        $this->assertSame(-32603, JsonAssert::at($stdio->lines()[1], 'error', 'code'));
        $event = $fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('DomainException', JsonAssert::at($event->error, 'type'));
        $this->assertSame('raw stream broke', JsonAssert::at($event->error, 'message'));
    }

    public function testAStreamOfAListIsRecordedFromItsResult(): void
    {
        $this->answer(static fn(JsonRpcRequest $request): array => [
            JsonRpcResponse::notification('notifications/progress', ['progress' => 1]),
            JsonRpcResponse::result($request->id, ['content' => [['type' => 'text', 'text' => 'listed']], 'isError' => false]),
            JsonRpcResponse::notification('notifications/progress', ['progress' => 2]),
        ]);
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(RecordingCallServer::class, $fixture->tracker());

        $stdio->send(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], null)]]);

        $lines = $stdio->lines();
        $this->assertCount(3, $lines);
        $this->assertSame(1, JsonAssert::at($lines[0], 'params', 'progress'));
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $this->text(JsonAssert::at($lines[1], 'result', 'content', 0, 'text')));
        $this->assertSame('listed', JsonAssert::at($lines[1], 'result', 'content', 1, 'text'));
        $this->assertSame(2, JsonAssert::at($lines[2], 'params', 'progress'));
        $this->assertSame('listed', JsonAssert::at($fixture->event()->response, 'content', 0, 'text'));
    }

    public function testAStreamWhoseLastAnswerIsAJsonRpcErrorIsOneErrorEvent(): void
    {
        $this->answer(static function (JsonRpcRequest $request): \Generator {
            yield JsonRpcResponse::notification('notifications/progress', ['progress' => 1]);
            yield JsonRpcResponse::error($request->id, -32602, 'bad stream');
        });
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(RecordingCallServer::class, $fixture->tracker());

        $stdio->send(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], null)]]);

        $this->assertSame('bad stream', JsonAssert::at($stdio->lines()[1] ?? null, 'error', 'message'));
        $event = $fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('bad stream', JsonAssert::at($event->error, 'message'));
    }

    public function testLaravelTestHelpersStillReadArrays(): void
    {
        $fixture = new LaravelTrackingFixture();
        $this->app?->afterResolving(ConformanceServer::class, $fixture->tracker());

        ConformanceServer::tool(EchoTool::class, ['msg' => 'hi', 'session_id' => 'start'])->assertSee('echo: hi')->assertHasNoErrors();
        ConformanceServer::tool(StructuredTool::class, ['session_id' => self::SESSION])->assertStructuredContent([
            Constants::MCP_SESSION_KEY => ['session_id' => self::SESSION, 'status' => 'active'],
            'echoed' => 'x',
        ]);

        $this->assertCount(2, $fixture->events());
    }

    public function testACallStartsTheDeliveryOfAWaitingEvent(): void
    {
        $fixture = new LaravelTrackingFixture();
        $fixture->transport->complete = false;
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker(new Options(enableTracing: false)));
        $fixture->queue->add(new QueuedEvent(new Event(sessionId: self::SESSION, projectId: LaravelTrackingFixture::PROJECT, eventType: Constants::TOOLS_CALL_EVENT_TYPE), null, null, []));

        $harness->callTool('echo', ['msg' => 'hi']);

        $this->assertSame(['queueLength' => 0, 'activeRequests' => 1], $fixture->queue->stats());
    }

    /**
     * The SSE bytes of one streamed `tools/call` over laravel/mcp's HTTP transport, sent the way Laravel sends a
     * `StreamedResponse`, with a supplied session (no mint-back).
     *
     * @param (\Closure(Server): void)|null $track
     */
    private function httpStream(?\Closure $track, string $tool): string
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => ['session_id' => self::SESSION], '_meta' => LaravelHarness::meta([], ['name' => 'c', 'version' => '1'])]], JSON_THROW_ON_ERROR);
        $transport = new HttpTransport(Request::create('/mcp/s', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_X_ONE' => 'a'], content: $body));
        $server = $this->app?->make(StreamingServer::class, ['transport' => $transport]);
        $this->assertInstanceOf(StreamingServer::class, $server);
        if ($track instanceof \Closure) {
            $track($server);
        }
        $server->start();
        $response = $transport->run();
        $this->assertSame('text/event-stream', $response->headers->get('Content-Type')); // a StreamedResponse
        $sent = new \ArrayObject();
        ob_start(static function (string $chunk) use ($sent): string {
            $sent->append($chunk);

            return '';
        }, 1);
        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        return implode('', $sent->getArrayCopy());
    }

    /** @return list<mixed> the names of a listing's tools */
    private function names(mixed $result): array
    {
        $tools = JsonAssert::at($result, 'tools');
        $this->assertIsList($tools);

        return array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $tools);
    }

    private function text(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * Binds the `RecordingMethod` that `RecordingCallServer` names for `tools/call`, with this answer.
     *
     * @param \Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse) $answer
     */
    private function answer(\Closure $answer): void
    {
        $this->app?->instance(RecordingMethod::class, new RecordingMethod(answer: $answer));
    }
}
