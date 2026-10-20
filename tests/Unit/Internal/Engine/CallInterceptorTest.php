<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Observed;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Handles\ArgumentStripper;
use AgentCat\Internal\Id\SessionId;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tokens\TokenEstimator;
use AgentCat\Options;
use AgentCat\Tests\Support\CallContexts;
use AgentCat\Tests\Support\FakeCallSeam;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use AgentCat\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CallInterceptorTest extends TestCase
{
    private const string ECHO_TOOL = '{"name":"echo","inputSchema":{"type":"object","properties":{"msg":{"type":"string"}},"required":["msg"]}}';

    private const string OWN_TOOL = '{"name":"own","inputSchema":{"type":"object","properties":{"session_id":{"type":"string"}},"required":["session_id"]}}';

    private const string STRUCTURED_TOOL = '{"name":"structured","inputSchema":{"type":"object","properties":{}},"outputSchema":{"type":"object","properties":{"echoed":{"type":"string"}}}}';

    private const string STRUCTURED_RESULT = '{"content":[{"type":"text","text":"x"}],"structuredContent":{"echoed":"x"}}';

    private const string CANNED = 'Unfortunately, we have shown you the full tool list. We have noted your feedback and will work to improve the tool list in the future.';

    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private const string POST_HANDLER = "Warning: AgentCat post-handler processing failed for tool echo; returning the customer's result untouched - ";

    /** The heuristic strip for a tool no listing has named. */
    private const array HEURISTIC = ['session_id', 'agent_id', 'context'];

    private MemoryLogger $logger;

    private RecordingExporter $exporter;

    private EventQueue $queue;

    private CallInterceptor $interceptor;

    private \DateTimeImmutable $t0;

    private int $ticks = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->exporter);
        $this->t0 = new \DateTimeImmutable('2026-10-17T12:00:00.000Z');
        // Advances 7 ms per call.
        $clock = fn(): \DateTimeImmutable => $this->t0->setTime(12, 0, 0, 7_000 * $this->ticks++);
        $this->interceptor = new CallInterceptor(new Publisher($this->queue, $clock), $clock);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-02-16')]
    #[Group('ac-01-79')]
    public function testASuppliedSessionStripsTheRegistryNamesAndRecordsTheCall(): void
    {
        $arguments = ['msg' => 'hi', 'context' => 'why', 'session_id' => $this->supplied()];
        $context = CallContexts::sample('echo', $arguments, clientName: 'cli', clientVersion: '9');
        $seam = $this->seam('echo', $arguments, $context);
        $seam->listing = [$this->tree(self::ECHO_TOOL)];

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame($seam->result, $returned); // a supplied session gets no block
        $this->assertSame([['stripped', ['session_id', 'context']]], $seam->calls);
        $event = $this->onlyEvent();
        $this->assertSame('mcp:tools/call', $event->eventType);
        $this->assertSame('echo', $event->resourceName);
        $this->assertEquals((object) ['request' => $context->request, 'extra' => $context->extra], $event->parameters);
        $this->assertSame('why', $event->userIntent);
        $this->assertSame($this->supplied(), $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'supplied'], $event->tags);
        $this->assertEquals($this->t0, $event->timestamp);
        $this->assertSame(7, $event->duration);
        $this->assertEquals(FakeCallSeam::ok(), $event->response);
        $this->assertIsInt($event->inputTokens);
        $this->assertSame(TokenEstimator::input($seam->arguments), $event->inputTokens);
        $this->assertSame(1, $event->outputTokens);
        $this->assertSame('proj_test', $event->projectId);
        $this->assertSame(['srv', '1', 'cli', '9'], [$event->serverName, $event->serverVersion, $event->clientName, $event->clientVersion]);
    }

    #[Group('ac-05-E7')]
    public function testASuccessIsNoError(): void
    {
        $this->interceptor->intercept($this->server(), $this->seam('echo', ['msg' => 'hi']));

        $event = $this->onlyEvent();
        $this->assertNull($event->isError);
        $this->assertNull($event->error);
    }

    #[Group('ac-02-30')]
    #[Group('ac-04-35')]
    #[Group('ac-02-38')]
    public function testAMissingSessionIsMintedAndAnnouncedOnTheWireOnly(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $event = $this->onlyEvent();
        $this->assertMatchesRegularExpression(SessionId::PATTERN, $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'minted'], $event->tags);
        $block = JsonAssert::at($returned, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $block);
        $this->assertStringContainsString('session_id: ' . $event->sessionId, $block);
        $this->assertSame('ok', JsonAssert::at($returned, 'content', 1, 'text'));
        $this->assertSame([['stripped', self::HEURISTIC], ['replace']], $seam->calls);
        $this->assertEquals(FakeCallSeam::ok(), $event->response);
    }

    #[Group('ac-02-31')]
    public function testAnUnrecognizedSessionIsReported(): void
    {
        $returned = $this->interceptor->intercept($this->server(), $this->seam('echo', ['msg' => 'hi', 'session_id' => 'not-a-real-id']));

        $block = JsonAssert::at($returned, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_UNRECOGNIZED, $block);
        $event = $this->onlyEvent();
        $this->assertSame('', $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'invalid'], $event->tags);
    }

    #[Group('ac-02-19')]
    #[Group('ac-04-13')]
    public function testACustomerSessionParameterIsForeign(): void
    {
        $server = $this->server();
        $server->injection->markDeclaredSessionParam('own');
        $seam = $this->seam('own', ['session_id' => 'mine']);

        $returned = $this->interceptor->intercept($server, $seam);

        $this->assertSame($seam->result, $returned);
        $event = $this->onlyEvent();
        $this->assertSame('', $event->sessionId);
        $this->assertSame(['agentcat_session_id_source' => 'foreign'], $event->tags);
    }

    #[Group('ac-02-33')]
    #[Group('ac-04-36')]
    #[Group('ac-02-23')]
    public function testAnErrorResultIsDecoratedAndRecordedAsAnError(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->result = $this->tree('{"content":[{"type":"text","text":"bad"},{"type":"text","text":"input"}],"isError":true}');

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $block = JsonAssert::at($returned, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $block);
        $this->assertSame('bad', JsonAssert::at($returned, 'content', 1, 'text'));
        $event = $this->onlyEvent();
        $this->assertTrue($event->isError);
        $this->assertEquals((object) ['message' => 'bad input', 'platform' => 'php'], $event->error);
        $this->assertEquals($seam->result, $event->response);
    }

    #[Group('ac-05-E5')]
    public function testTheToolsOwnExceptionWinsOverTheResultText(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->result = $this->tree('{"content":[{"type":"text","text":"tool boom"}],"isError":true}');
        $seam->observed = new Observed($seam->result, thrown: new \RuntimeException('tool boom', 0, new \LogicException('cause')));

        $this->interceptor->intercept($this->server(), $seam);

        $error = $this->onlyEvent()->error;
        $this->assertSame('RuntimeException', JsonAssert::at($error, 'type'));
        $this->assertSame('tool boom', JsonAssert::at($error, 'message'));
        $this->assertSame('LogicException', JsonAssert::at($error, 'chained_errors', 0, 'type'));
    }

    #[Group('ac-05-E6')]
    #[Group('ac-02-26')]
    public function testAProtocolErrorIsRecordedAndPassedOnUnchanged(): void
    {
        $seam = $this->seam('nope', ['x' => 1]);
        $seam->observed = new Observed(protocolError: 'Tool not found: "nope".');

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame($seam->result, $returned);
        $this->assertSame([['stripped', self::HEURISTIC]], $seam->calls);
        $event = $this->onlyEvent();
        $this->assertTrue($event->isError);
        $this->assertEquals((object) ['message' => 'Tool not found: "nope".', 'platform' => 'php'], $event->error);
        $this->assertNull($event->response);
        $this->assertNull($event->outputTokens);
        $this->assertSame(7, $event->duration);
    }

    #[Group('ac-05-E5')]
    public function testAProtocolErrorPrefersTheToolsOwnException(): void
    {
        $seam = $this->seam('boom', []);
        $seam->observed = new Observed(protocolError: 'Error while executing tool', thrown: new \RuntimeException('tool boom'));

        $this->interceptor->intercept($this->server(), $seam);

        $error = $this->onlyEvent()->error;
        $this->assertSame('RuntimeException', JsonAssert::at($error, 'type'));
        $this->assertSame('tool boom', JsonAssert::at($error, 'message'));
    }

    #[Group('ac-02-25')]
    public function testALibraryExceptionIsRecordedAndRethrownUnchanged(): void
    {
        $failure = new \DomainException('lib');
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->result = $failure;

        $thrown = Thrown::by(\DomainException::class, fn(): mixed => $this->interceptor->intercept($this->server(), $seam));

        $this->assertSame($failure, $thrown);
        $event = $this->onlyEvent();
        $this->assertTrue($event->isError);
        $this->assertSame('DomainException', JsonAssert::at($event->error, 'type'));
        $this->assertSame(7, $event->duration);
        $this->assertNull($event->response);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-02-29')]
    public function testACallAfterAThrowingCallIsNormal(): void
    {
        $server = $this->server();
        $failing = $this->seam('echo', ['msg' => 'a']);
        $failing->result = new \DomainException('lib');
        Thrown::by(\DomainException::class, fn(): mixed => $this->interceptor->intercept($server, $failing));

        $this->interceptor->intercept($server, $this->seam('echo', ['msg' => 'b']));

        $events = $this->events();
        $this->assertCount(2, $events);
        $second = $events[1];
        $this->assertNull($second->isError);
        $this->assertNull($second->error);
        $this->assertEquals(FakeCallSeam::ok(), $second->response);
    }

    #[Group('ac-02-35')]
    #[Group('ac-04-42')]
    public function testAnInputRequiredRoundIsTaggedAndNotDecorated(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->result = $this->tree('{"resultType":"input_required","inputRequests":{}}');

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame($seam->result, $returned);
        $this->assertSame([['stripped', self::HEURISTIC]], $seam->calls);
        $event = $this->onlyEvent();
        $this->assertSame(['agentcat_session_id_source' => 'minted', 'agentcat_mrtr' => 'input_required'], $event->tags);
        $this->assertEquals($seam->result, $event->response);
    }

    #[Group('ac-02-35')]
    public function testAContinuationRoundIsTheFirstTag(): void
    {
        $arguments = ['msg' => 'hi'];

        $this->interceptor->intercept($this->server(), $this->seam('echo', $arguments, CallContexts::sample('echo', $arguments, continuation: true)));

        $this->assertSame(['agentcat_mrtr' => 'continuation', 'agentcat_session_id_source' => 'minted'], $this->onlyEvent()->tags);
    }

    /** B15 parity: the second value replaces the first and keeps its position. */
    #[Group('ac-02-35')]
    #[Group('ac-04-42')]
    public function testAContinuationThatAsksForMoreInputKeepsTheFirstPosition(): void
    {
        $arguments = ['msg' => 'hi'];
        $seam = $this->seam('echo', $arguments, CallContexts::sample('echo', $arguments, continuation: true));
        $seam->result = $this->tree('{"resultType":"input_required","inputRequests":{}}');

        $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame(['agentcat_mrtr' => 'input_required', 'agentcat_session_id_source' => 'minted'], $this->onlyEvent()->tags);
    }

    public function testAContextWithOnlyTheRequestDataIsAPlainRound(): void
    {
        $sample = CallContexts::sample('echo', ['msg' => 'hi']);
        $context = new CallContext($sample->request, $sample->extra, $sample->toolCall);

        $this->interceptor->intercept($this->server(), $this->seam('echo', ['msg' => 'hi'], $context));

        $event = $this->onlyEvent();
        $this->assertSame(['agentcat_session_id_source' => 'minted'], $event->tags);
        $this->assertSame([null, null], [$event->clientName, $event->clientVersion]);
    }

    #[Group('ac-02-45')]
    public function testTheProtocolVersionClaimIsTagged(): void
    {
        $arguments = ['msg' => 'hi'];

        $this->interceptor->intercept($this->server(), $this->seam('echo', $arguments, CallContexts::sample('echo', $arguments, protocolVersion: '2026-07-28')));

        $this->assertSame(['agentcat_session_id_source' => 'minted', 'agentcat_protocol_version' => '2026-07-28'], $this->onlyEvent()->tags);
    }

    #[Group('ac-02-36')]
    #[Group('ac-04-39')]
    #[Group('ac-04-43')]
    public function testADeclaredOutputSchemaGetsTheStructuredMirror(): void
    {
        $seam = $this->seam('structured');
        $seam->listing = [$this->tree(self::STRUCTURED_TOOL)];
        $seam->result = $this->tree(self::STRUCTURED_RESULT);

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame(['mcp_session', 'echoed'], $this->keys(JsonAssert::at($returned, 'structuredContent')));
        $block = JsonAssert::at($returned, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $block);
        $event = $this->onlyEvent();
        $this->assertEquals((object) ['echoed' => 'x'], JsonAssert::at($event->response, 'structuredContent'));
        $this->assertEquals($this->tree(self::STRUCTURED_RESULT), $event->response, 'the original result: no block, no mcp_session');
    }

    #[Group('ac-04-41')]
    public function testAToolOutsideTheOutputRegistryGetsNoMirror(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->listing = [$this->tree(self::ECHO_TOOL)];
        $seam->result = $this->tree(self::STRUCTURED_RESULT);

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame(['echoed'], $this->keys(JsonAssert::at($returned, 'structuredContent')));
        $block = JsonAssert::at($returned, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $block);
    }

    #[Group('ac-04-41')]
    public function testNoOutputRegistryAtAllMirrorsAnyway(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->result = $this->tree(self::STRUCTURED_RESULT);

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame(['mcp_session', 'echoed'], $this->keys(JsonAssert::at($returned, 'structuredContent')));
    }

    #[Group('ac-02-20')]
    public function testTheRegistriesAreRebuiltOnceOnDemand(): void
    {
        $server = $this->server();
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->listing = [$this->tree(self::ECHO_TOOL)];

        $this->interceptor->intercept($server, $seam);
        $this->interceptor->intercept($server, $seam);

        $this->assertSame(1, $seam->listCalls);
        $this->assertSame([self::REBUILT], $this->logger->messages());
        $this->assertSame([['stripped', ['session_id', 'context']], ['replace'], ['stripped', ['session_id', 'context']], ['replace']], $seam->calls);
    }

    /** D66 (B10 fixed): the rebuild reads page 2 for a tool that page 1 does not name; before, it read page 1 only. */
    #[Group('ac-02-19')]
    public function testAToolOnALaterPageIsStrippedExactly(): void
    {
        $seam = $this->seam('own', ['session_id' => 'theirs', 'agent_id' => 'a1']);
        $seam->listing = [$this->tree(self::ECHO_TOOL)];
        $seam->nextCursor = 'p2';
        $seam->pages = ['p2' => [[$this->tree(self::OWN_TOOL)], null]];

        $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame([null, 'p2'], $seam->cursors);
        $this->assertSame(['stripped', ['context']], $seam->calls[0] ?? null); // before D66: the heuristic session_id, agent_id, context
        $event = $this->onlyEvent();
        $this->assertSame('foreign', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame('', $event->sessionId);
    }

    #[Group('ac-02-21')]
    public function testAFailedRebuildFallsBackToTheHeuristicStrip(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->listing = new \RuntimeException('list failed');

        $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame(['stripped', self::HEURISTIC], $seam->calls[0] ?? null);
        $this->assertSame(['Warning: registry rebuild-on-demand failed - RuntimeException: list failed'], $this->logger->messages());
    }

    #[Group('ac-02-28')]
    public function testAFailedSetupRunsTheOriginalHandlerUntraced(): void
    {
        $seam = new FakeCallSeam('echo', CallContexts::arguments(['msg' => 'hi', 'session_id' => 'start']), new \RuntimeException('describe'));

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame($seam->result, $returned);
        $this->assertSame([['original']], $seam->calls);
        $this->assertSame([], $this->events());
        $this->assertSame(['Warning: AgentCat tracing failed for tool echo, falling back to original handler - RuntimeException: describe'], $this->logger->messages());
    }

    #[Group('ac-02-28')]
    public function testAFailedSetupStillAnswersGetMoreTools(): void
    {
        $seam = new FakeCallSeam('get_more_tools', CallContexts::arguments(['context' => 'need X']), new \RuntimeException('describe'));

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame([['answer']], $seam->calls);
        $this->assertSame(self::CANNED, JsonAssert::at($returned, 'content', 0, 'text'));
        $this->assertSame([], $this->events());
    }

    #[Group('ac-02-27')]
    public function testAFailedDecorationReturnsTheResultUntouchedWithoutAnEvent(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->replaceFailure = new \RuntimeException('replace');

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame($seam->result, $returned);
        $this->assertSame([], $this->events());
        $this->assertSame([self::POST_HANDLER . 'RuntimeException: replace'], $this->logger->messages());
    }

    /** TS finish(): a publish failure forfeits the decoration too. */
    public function testAFailedPublishReturnsTheResultUntouched(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);

        $returned = $this->interceptor->intercept($this->server(serverInfo: $this->failingServerInfo()), $seam);

        $this->assertSame($seam->result, $returned);
        $this->assertSame([], $this->events());
        $this->assertSame([self::POST_HANDLER . 'LogicException: info'], $this->logger->messages());
    }

    /** D32: a failure while recording a library exception never replaces that exception. */
    public function testAFailedErrorPublishStillRethrowsTheLibraryException(): void
    {
        $failure = new \DomainException('lib');
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->result = $failure;

        $thrown = Thrown::by(\DomainException::class, fn(): mixed => $this->interceptor->intercept($this->server(serverInfo: $this->failingServerInfo()), $seam));

        $this->assertSame($failure, $thrown);
        $this->assertSame([], $this->events());
        $this->assertSame([self::POST_HANDLER . 'LogicException: info'], $this->logger->messages());
    }

    public function testAResultThatCompletesLaterIsPublishedWhenItCompletes(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $finish = null;
        $seen = null;
        $seam->later = static function (\stdClass $result, \Closure $complete, \Closure $fail) use (&$finish, &$seen): \stdClass {
            $seen = $result;
            $finish = $complete;

            return (object) ['pending' => true];
        };

        $answer = $this->interceptor->intercept($this->server(), $seam);

        $this->assertEquals((object) ['pending' => true], $answer);
        $this->assertSame($seam->result, $seen);
        $this->assertSame([], $this->events()); // nothing until the stream ends
        $this->assertInstanceOf(\Closure::class, $finish);
        $final = $finish(FakeCallSeam::ok());
        $event = $this->onlyEvent();
        $block = JsonAssert::at($final, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $block);
        $this->assertStringContainsString('session_id: ' . $event->sessionId, $block);
        $this->assertEquals(FakeCallSeam::ok(), $event->response);
        $this->assertSame([['stripped', self::HEURISTIC], ['replace']], $seam->calls);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-05-E5')]
    public function testAStreamThatFailsIsOneErrorEvent(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $fail = null;
        $seam->later = static function (\stdClass $result, \Closure $complete, \Closure $failed) use (&$fail): \stdClass {
            $fail = $failed;

            return (object) [];
        };

        $this->interceptor->intercept($this->server(), $seam);
        $this->assertInstanceOf(\Closure::class, $fail);
        $fail(new \DomainException('stream broke'));

        $event = $this->onlyEvent();
        $this->assertTrue($event->isError);
        $this->assertSame('DomainException', JsonAssert::at($event->error, 'type'));
        $this->assertSame('stream broke', JsonAssert::at($event->error, 'message'));
        $this->assertNull($event->response);
        $this->assertSame([], $this->logger->messages());
    }

    /** D32: a failure while recording a failed stream is logged; the stream's own exception stays the seam's. */
    public function testAFailedStreamWhosePublishFailsIsLogged(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $fail = null;
        $seam->later = static function (\stdClass $result, \Closure $complete, \Closure $failed) use (&$fail): \stdClass {
            $fail = $failed;

            return (object) [];
        };

        $this->interceptor->intercept($this->server(serverInfo: $this->failingServerInfo()), $seam);
        $this->assertInstanceOf(\Closure::class, $fail);
        $fail(new \DomainException('stream broke'));

        $this->assertSame([], $this->events());
        $this->assertSame([self::POST_HANDLER . 'LogicException: info'], $this->logger->messages());
    }

    public function testALaterThatThrowsFallsBackToAnOrdinaryFinish(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->later = static fn(): never => throw new \LogicException('later broke');

        $answer = $this->interceptor->intercept($this->server(), $seam);

        $this->assertCount(1, $this->events());
        $this->assertSame([self::POST_HANDLER . 'LogicException: later broke'], $this->logger->messages());
        $block = JsonAssert::at($answer, 'content', 0, 'text');
        $this->assertIsString($block);
        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $block);
    }

    public function testWithTracingOffAResultIsNeverAskedToCompleteLater(): void
    {
        $seam = $this->seam('echo', ['msg' => 'hi']);
        $seam->later = static fn(): never => throw new \LogicException('later asked');

        $answer = $this->interceptor->intercept($this->server(new Options(enableTracing: false)), $seam);

        $this->assertSame($seam->result, $answer);
        $this->assertSame([], $this->events());
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-02-40')]
    #[Group('ac-04-46')]
    public function testGetMoreToolsIsAnsweredWithoutTheCustomer(): void
    {
        $seam = $this->seam('get_more_tools', ['context' => 'need X']);
        // AgentCat records its own answer; it never asks the library what that answer means.
        $seam->observed = new Observed(protocolError: 'never read');

        $returned = $this->interceptor->intercept($this->server(), $seam);

        $this->assertSame([['answer'], ['replace']], $seam->calls);
        $event = $this->onlyEvent();
        $issued = implode("\n", [Constants::MINT_BACK_HEADER_ISSUED, 'session_id: ' . $event->sessionId, Constants::MINT_BACK_ISSUED_BODY]);
        $this->assertSame([$issued, self::CANNED], [JsonAssert::at($returned, 'content', 0, 'text'), JsonAssert::at($returned, 'content', 1, 'text')]);
        $this->assertSame('get_more_tools', $event->resourceName);
        $this->assertSame('need X', $event->userIntent);
        $this->assertNull($event->isError);
        $this->assertEquals((object) ['content' => [(object) ['type' => 'text', 'text' => self::CANNED]]], $event->response);
        $this->assertContains('Missing tool reported (context length: 6)', $this->logger->messages());
    }

    /**
     * D66: a get_more_tools call rebuilds like any other tool. AgentCat's own is on no page, so the read goes to the
     * last page. Its entry then names the handles AgentCat injected, and they are AgentCat's.
     */
    #[Group('ac-02-40')]
    public function testAGetMoreToolsCallRebuildsAndItsHandlesAreAgentCats(): void
    {
        $server = $this->server(new Options(enableAgentTracking: true));
        $seam = $this->seam('get_more_tools', ['context' => 'need X', 'session_id' => $this->supplied(), 'agent_id' => 'a1']);
        $seam->listing = [$this->tree(self::ECHO_TOOL)];
        $seam->nextCursor = 'p2';
        $seam->pages = ['p2' => [[$this->tree(self::STRUCTURED_TOOL)], null]];

        $this->interceptor->intercept($server, $seam);

        $this->assertSame([null, 'p2'], $seam->cursors);
        $this->assertSame(['session_id', 'agent_id'], ArgumentStripper::names('get_more_tools', $server->registry));
        $this->assertSame([['answer']], $seam->calls); // AgentCat answers; a supplied session gets no block
        $event = $this->onlyEvent();
        $this->assertSame($this->supplied(), $event->sessionId);
        $this->assertSame('supplied', $event->tags[Constants::TAG_SESSION_SOURCE] ?? null);
        $this->assertSame('a1', $event->tags[Constants::TAG_AGENT_ID] ?? null);
    }

    #[Group('ac-02-41')]
    public function testWithReportMissingOffGetMoreToolsReachesTheLibrary(): void
    {
        $seam = $this->seam('get_more_tools', ['context' => 'need X']);

        $this->interceptor->intercept($this->server(new Options(enableReportMissing: false)), $seam);

        $this->assertSame(['stripped', ['session_id', 'agent_id']], $seam->calls[0] ?? null);
        $this->assertNotContains(['answer'], $seam->calls);
        $this->assertNull($this->onlyEvent()->userIntent); // the context intent never covers get_more_tools
    }

    #[Group('ac-02-42')]
    #[Group('ac-04-47')]
    public function testWithTracingOffNothingIsTracedOrStripped(): void
    {
        $server = $this->server(new Options(enableTracing: false));
        $more = $this->seam('get_more_tools', ['context' => 'need X']);
        $more->listing = [$this->tree(self::ECHO_TOOL)];
        $echo = $this->seam('echo', ['msg' => 'hi', 'context' => 'why']);
        $echo->listing = [$this->tree(self::ECHO_TOOL)];

        $answer = $this->interceptor->intercept($server, $more);
        $returned = $this->interceptor->intercept($server, $echo);

        $this->assertSame('{"content":[{"type":"text","text":"' . self::CANNED . '"}]}', json_encode($answer, JSON_THROW_ON_ERROR));
        $this->assertSame([['answer']], $more->calls);
        $this->assertSame([['original']], $echo->calls); // unstripped (B06)
        $this->assertSame($echo->result, $returned);
        $this->assertSame([0, 0], [$more->listCalls, $echo->listCalls]);
        $this->assertSame([], $this->events());
    }

    #[Group('ac-02-17')]
    public function testWithToolCallContextOffTheContextIsNoIntent(): void
    {
        $this->interceptor->intercept($this->server(new Options(enableToolCallContext: false)), $this->seam('echo', ['msg' => 'hi', 'context' => 'why']));

        $this->assertNull($this->onlyEvent()->userIntent);
    }

    #[Group('ac-04-32')]
    public function testGetMoreToolsKeepsItsIntentWithToolCallContextOff(): void
    {
        $this->interceptor->intercept($this->server(new Options(enableToolCallContext: false)), $this->seam('get_more_tools', ['context' => 'need X']));

        $this->assertSame('need X', $this->onlyEvent()->userIntent);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function droppedIntents(): iterable
    {
        yield 'int' => [42, 'int'];
        yield 'float' => [4.5, 'float'];
        yield 'bool' => [true, 'bool'];
        yield 'object' => [['a' => 1], 'stdClass'];
        yield 'object with a length' => [['length' => 3], 'stdClass'];
    }

    /** Header conflict 2 (B16, N10): no event, one line, and the hooks of that call never run. */
    #[DataProvider('droppedIntents')]
    public function testANumberBooleanOrObjectIntentDropsTheEventBeforeAnyHookRuns(mixed $context, string $type): void
    {
        $runs = 0;
        $server = $this->server(new Options(identify: static function (ToolCall $call) use (&$runs): ?Identity {
            ++$runs;

            return null;
        }));

        $returned = $this->interceptor->intercept($server, $this->seam('echo', ['msg' => 'hi', 'context' => $context]));

        $this->assertSame('ok', JsonAssert::at($returned, 'content', 1, 'text'));
        $this->assertSame([], $this->events());
        $this->assertSame(0, $runs);
        $this->assertSame(['Failed to truncate event: UnexpectedValueException: userIntent is not a string (' . $type . ')'], $this->logger->messages());
    }

    /** TS truncation passes a null intent through, so the event stays. */
    public function testANullIntentIsNoIntent(): void
    {
        $this->interceptor->intercept($this->server(), $this->seam('echo', ['msg' => 'hi', 'context' => null]));

        $this->assertNull($this->onlyEvent()->userIntent);
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{list<mixed>}> */
    public static function listIntents(): iterable
    {
        yield 'empty' => [[]];
        yield 'one item' => [[1]];
        yield 'long' => [array_fill(0, 3000, 'x')];
    }

    /**
     * TS truncation keeps a list (it has a length) and sends it raw; `userIntent` holds strings only, so it is null here.
     *
     * @param list<mixed> $context
     */
    #[DataProvider('listIntents')]
    public function testAListIntentIsPublishedWithoutAnIntent(array $context): void
    {
        $runs = 0;
        $server = $this->server(new Options(identify: static function (ToolCall $call) use (&$runs): ?Identity {
            ++$runs;

            return null;
        }));

        $this->interceptor->intercept($server, $this->seam('echo', ['msg' => 'hi', 'context' => $context]));

        $this->assertNull($this->onlyEvent()->userIntent);
        $this->assertSame(1, $runs);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-24')]
    #[Group('ac-01-22')]
    public function testTheAgentIdIsTaggedCleanAndRecordedRaw(): void
    {
        $agent = "line1\nline2" . str_repeat('a', 250);

        $this->interceptor->intercept($this->server(new Options(enableAgentTracking: true)), $this->seam('echo', ['msg' => 'hi', 'agent_id' => $agent]));

        $event = $this->onlyEvent();
        $this->assertSame('line1 line2' . str_repeat('a', 189), $event->tags['agentcat_agent_id'] ?? null);
        $this->assertSame('supplied', $event->tags['agentcat_agent_id_source'] ?? null);
        $this->assertSame($agent, JsonAssert::at($event->parameters, 'request', 'params', 'arguments', 'agent_id'));
    }

    #[Group('ac-01-25')]
    public function testWithAgentTrackingOffTheAgentIdIsNotTagged(): void
    {
        $this->interceptor->intercept($this->server(), $this->seam('echo', ['msg' => 'hi', 'agent_id' => 'a1']));

        $this->assertSame(['agentcat_session_id_source' => 'minted'], $this->onlyEvent()->tags);
    }

    #[Group('ac-01-30')]
    public function testHookModeAnnouncesNothingAndTheHookFinalizesTheSession(): void
    {
        $server = $this->server(new Options(resolveSessionId: static fn(ToolCall $call): ?string => null));
        $seam = $this->seam('echo', ['msg' => 'hi']);

        $returned = $this->interceptor->intercept($server, $seam);

        $this->assertSame($seam->result, $returned);
        $this->assertSame([['stripped', self::HEURISTIC]], $seam->calls);
        $event = $this->onlyEvent();
        $this->assertSame(['agentcat_session_id_source' => 'minted'], $event->tags);
        $this->assertMatchesRegularExpression(SessionId::PATTERN, $event->sessionId);
    }

    /** D04: the hooks get the context's ToolCall, and only when the queue runs. */
    public function testTheHooksRunLaterWithTheCallsToolCall(): void
    {
        /** @var \ArrayObject<int, ToolCall> $seen */
        $seen = new \ArrayObject();
        $server = $this->server(new Options(identify: static function (ToolCall $call) use ($seen): Identity {
            $seen->append($call);

            return new Identity('u-1');
        }));
        $context = CallContexts::sample('echo', ['msg' => 'hi']);

        $this->interceptor->intercept($server, $this->seam('echo', ['msg' => 'hi'], $context));

        $this->assertSame([], $seen->getArrayCopy());
        $this->assertSame('u-1', $this->onlyEvent()->identifyActorGivenId);
        $this->assertSame([$context->toolCall], $seen->getArrayCopy());
    }

    /** Header conflict 4: empty arguments count as absent for the token estimate. */
    public function testEmptyArgumentsHaveNoInputTokens(): void
    {
        $this->interceptor->intercept($this->server(), $this->seam('echo'));

        $this->assertNull($this->onlyEvent()->inputTokens);
    }

    /** @return iterable<string, array{string, string}> */
    public static function resourceNames(): iterable
    {
        yield 'empty' => ['', 'Unknown Tool'];
        yield 'zero' => ['0', '0']; // JS `"0" || "Unknown Tool"` is "0"
    }

    #[DataProvider('resourceNames')]
    public function testTheResourceNameFallsBackForAnEmptyName(string $name, string $expected): void
    {
        $this->interceptor->intercept($this->server(), $this->seam($name, ['msg' => 'hi']));

        $this->assertSame($expected, $this->onlyEvent()->resourceName);
    }

    public function testTheDefaultClockIsUtcNow(): void
    {
        $interceptor = new CallInterceptor(new Publisher($this->queue));
        $before = new \DateTimeImmutable();

        $interceptor->intercept($this->server(), $this->seam('echo', ['msg' => 'hi']));

        $event = $this->onlyEvent();
        $this->assertGreaterThanOrEqual((int) $before->format('Uv'), (int) $event->timestamp->format('Uv'));
        $this->assertSame('UTC', $event->timestamp->getTimezone()->getName());
        $this->assertIsInt($event->duration);
        $this->assertGreaterThanOrEqual(0, $event->duration);
    }

    private function supplied(): string
    {
        return 'ses_' . str_repeat('a', 27);
    }

    /** @param (\Closure(): array{0: ?string, 1: ?string})|null $serverInfo */
    private function server(?Options $options = null, ?\Closure $serverInfo = null): TrackedServer
    {
        return new TrackedServer('proj_test', $options ?? new Options(), $serverInfo ?? static fn(): array => ['srv', '1'], new Publisher($this->queue));
    }

    /** @return \Closure(): array{0: ?string, 1: ?string} */
    private function failingServerInfo(): \Closure
    {
        return static fn(): array => throw new \LogicException('info');
    }

    /** @param array<array-key, mixed> $arguments */
    private function seam(string $name, array $arguments = [], ?CallContext $context = null): FakeCallSeam
    {
        return new FakeCallSeam($name, CallContexts::arguments($arguments), $context ?? CallContexts::sample($name, $arguments));
    }

    private function tree(string $json): \stdClass
    {
        $value = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    /** @return list<string> */
    private function keys(mixed $tree): array
    {
        $this->assertInstanceOf(\stdClass::class, $tree);

        return array_map(strval(...), array_keys(get_object_vars($tree)));
    }

    /** @return list<Event> */
    private function events(): array
    {
        $this->queue->flush();

        return $this->exporter->events;
    }

    private function onlyEvent(): Event
    {
        $events = $this->events();
        $this->assertCount(1, $events);

        return $events[0];
    }
}
