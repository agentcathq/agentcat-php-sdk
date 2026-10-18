<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Tools\GetMoreTools;
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
use AgentCat\Tests\Support\LaravelMcp\RecordingCallServer;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use AgentCat\Tests\Support\LaravelMcp\RequestEchoServer;
use AgentCat\Tests\Support\LaravelMcp\StreamingServer;
use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\StructuredTool;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\Group;

final class CallToolTest extends LaravelTestCase
{
    private const string SESSION = 'ses_bbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
