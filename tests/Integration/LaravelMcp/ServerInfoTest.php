<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Guard;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\PagedServer;
use AgentCat\Tests\Support\LaravelMcp\ServerInfo\DemoServer;
use AgentCat\Tests\Support\LaravelMcp\ServerInfo\FailingMethod;
use AgentCat\Tests\Support\LaravelMcp\ServerInfo\ToolsOnlyServer;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\ServerInfoRecorder;
use Laravel\Mcp\Server;

/** The server info heartbeat of a laravel/mcp server, from a real request to the body of the POST. */
final class ServerInfoTest extends LaravelTestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private MemoryLogger $logger;

    private RecordingExporter $exporter;

    private RecordingHttpChannel $channel;

    private EventQueue $queue;

    private float $now = 1000.0;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        ServerInfoRecorder::remove();
        Heartbeat::setClock(fn(): float => $this->now);
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->exporter = new RecordingExporter();
        $this->channel = new RecordingHttpChannel();
        $this->queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->exporter, channel: $this->channel, outlivesRequest: true);
        FailingMethod::$calls = 0;
    }

    #[\Override]
    protected function tearDown(): void
    {
        Heartbeat::setClock(null);
        ServerInfoRecorder::install();
        Log::reset();
        parent::tearDown();
    }

    public function testV1OneToolCallSendsWhatAClientGetsFromTheServer(): void
    {
        $this->oldMemory();
        $harness = $this->harness(DemoServer::class);

        $answer = $harness->callTool('alpha', []);
        $this->assertSame('ok', $this->text($answer));
        $this->assertHeartbeats(0, 'nothing is sent while the request is answered');
        $this->queue->flush();

        $this->assertHeartbeats(1);
        $body = $this->body(0);
        $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($body));
        $this->assertSame([self::PROJECT, 'demo-server', '1.2.3', '', 'Use the demo tools.'], [JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'server_version'), JsonAssert::at($body, 'server_title'), JsonAssert::at($body, 'instructions')]);
        $listed = $harness->listTools()->tools;
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], JsonAssert::column($listed, 'name'));
        $this->assertEquals($listed, JsonAssert::at($body, 'features', 'tools'), 'the tools are what a client gets from tools/list');
        $beta = JsonAssert::at($body, 'features', 'tools', 1);
        $this->assertSame([str_repeat('b', 2000), 'Beta Tool'], [JsonAssert::at($beta, 'description'), JsonAssert::at($beta, 'title')]);
        $this->assertSame(['n', 'session_id', 'context'], JsonAssert::keys(JsonAssert::at($beta, 'inputSchema', 'properties')));
        $this->assertSame(['sum', 'mcp_session'], JsonAssert::keys(JsonAssert::at($beta, 'outputSchema', 'properties')));
        $this->assertEquals(JsonAssert::at($harness->request('resources/list'), 'result', 'resources'), JsonAssert::at($body, 'features', 'resources'));
        $this->assertEquals(JsonAssert::at($harness->request('resources/templates/list'), 'result', 'resourceTemplates'), JsonAssert::at($body, 'features', 'resourceTemplates'));
        $this->assertEquals(JsonAssert::at($harness->request('prompts/list'), 'result', 'prompts'), JsonAssert::at($body, 'features', 'prompts'));
        $this->assertSame([2, 1, 2], [count(JsonAssert::list(JsonAssert::at($body, 'features', 'resources'))), count(JsonAssert::list(JsonAssert::at($body, 'features', 'resourceTemplates'))), count(JsonAssert::list(JsonAssert::at($body, 'features', 'prompts')))]);
        $this->assertSame('file:///{name}.txt', JsonAssert::at($body, 'features', 'resourceTemplates', 0, 'uriTemplate'));
        $this->assertSame('who', JsonAssert::at($body, 'features', 'prompts', 0, 'arguments', 0, 'name'));
    }

    public function testV2AToolListOnTwoPagesArrivesWholeWithGetMoreToolsOnce(): void
    {
        $this->oldMemory();
        $harness = $this->harness(PagedServer::class);
        $this->assertObjectHasProperty('nextCursor', $harness->listTools(), 'the server pages its tool list');
        $this->queue->flush();
        $this->assertHeartbeats(1, 'the list request started the heartbeat');

        $names = JsonAssert::column(JsonAssert::at($this->body(0), 'features', 'tools'), 'name');

        $this->assertCount(17, $names);
        $this->assertSame(['echo', 't01', 't15', 'get_more_tools'], [$names[0], $names[1], $names[15], $names[16]]);
    }

    public function testV3AServerWithToolsOnlyHasNoOtherKey(): void
    {
        $this->oldMemory();
        $harness = $this->harness(ToolsOnlyServer::class);

        $harness->callTool('alpha', []);
        $this->queue->flush();

        $this->assertSame(['tools'], JsonAssert::keys(JsonAssert::at($this->body(0), 'features')));
        $this->assertSame(['alpha', 'get_more_tools'], JsonAssert::column(JsonAssert::at($this->body(0), 'features', 'tools'), 'name'));
    }

    public function testV4AFailingPromptListHandlerLeavesOnlyItsKeyOut(): void
    {
        $this->oldMemory();
        $harness = $this->harness(DemoServer::class, methods: ['prompts/list' => FailingMethod::class]);

        $harness->callTool('alpha', []);
        $this->queue->flush();

        $features = JsonAssert::at($this->body(0), 'features');
        $this->assertSame(['tools', 'resources', 'resourceTemplates'], JsonAssert::keys($features));
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], JsonAssert::column(JsonAssert::at($features, 'tools'), 'name'));
        $this->assertStringContainsString('| without prompts (RuntimeException: the handler failed)', implode("\n", $this->logger->messages()));
    }

    public function testV5AFailingToolListHandlerSendsNothing(): void
    {
        $this->oldMemory();
        // The customer's handler is in place before AgentCat tracks the server, so AgentCat's entry delegates to it.
        $harness = $this->harness(DemoServer::class, before: ['tools/list' => FailingMethod::class]);

        $answer = $harness->callTool('alpha', []);
        $this->queue->flush();

        $this->assertSame('ok', $this->text($answer));
        $this->assertHeartbeats(0);
        $this->assertContains('Skipped the server info heartbeat: the tool list failed - RuntimeException: the handler failed', $this->logger->messages());
    }

    public function testV10AFailingListHandlerOrApiNeverReachesTheToolCall(): void
    {
        $this->oldMemory();
        $this->channel->complete = false; // the API never answers
        $harness = $this->harness(DemoServer::class, methods: ['resources/list' => FailingMethod::class]);

        $answer = $harness->callTool('alpha', []);

        $this->assertSame('ok', $this->text($answer));
        $this->assertSame(0, FailingMethod::$calls, 'no list handler runs while the request is answered');
        $this->assertFalse($this->queue->flush(1.0));
        $this->assertSame(1, FailingMethod::$calls);
        $this->assertHeartbeats(1);
        $this->assertSame('ok', $this->text($harness->callTool('gamma', [])), 'the next call is answered while the heartbeat is still on its way');
    }

    public function testV11TheRedactionFunctionRunsOnTheTextAndNotOnTheNames(): void
    {
        $this->oldMemory();
        $harness = $this->harness(DemoServer::class, new Options(redactSensitiveInformation: static fn(string $text): string => str_replace(['demo', 'file', 'beta', 'Beta'], '[x]', $text)));

        $harness->callTool('alpha', []);
        $this->queue->flush();

        $body = $this->body(0);
        $this->assertSame(['Use the [x] tools.', 'demo-server'], [JsonAssert::at($body, 'instructions'), JsonAssert::at($body, 'server_name')]);
        $this->assertSame(['beta', '[x] Tool'], [JsonAssert::at($body, 'features', 'tools', 1, 'name'), JsonAssert::at($body, 'features', 'tools', 1, 'title')]);
        $this->assertSame(['file:///a.txt', 'A [x]'], [JsonAssert::at($body, 'features', 'resources', 0, 'uri'), JsonAssert::at($body, 'features', 'resources', 0, 'description')]);
        $this->assertSame(['file:///{name}.txt', 'Any [x]'], [JsonAssert::at($body, 'features', 'resourceTemplates', 0, 'uriTemplate'), JsonAssert::at($body, 'features', 'resourceTemplates', 0, 'description')]);
    }

    public function testV15TheHeartbeatNamesTheServerAsTheEventOfTheCallDoes(): void
    {
        $this->oldMemory();
        $harness = $this->harness(DemoServer::class);

        $harness->callTool('alpha', []);
        $this->queue->flush();

        $this->assertCount(1, $this->exporter->events);
        $event = $this->exporter->events[0];
        $this->assertSame(['demo-server', '1.2.3'], [$event->serverName, $event->serverVersion]);
        $this->assertSame([$event->serverName, $event->serverVersion, $event->projectId], [JsonAssert::at($this->body(0), 'server_name'), JsonAssert::at($this->body(0), 'server_version'), JsonAssert::at($this->body(0), 'project_id')]);
    }

    public function testV17InANewMemoryOnlyAToolListRequestAndAFeedbackNoteSend(): void
    {
        $harness = $this->harness(DemoServer::class);

        $harness->callTool('alpha', []);
        $this->queue->flush();
        $this->assertHeartbeats(0, 'none after the tool call');

        $harness->listTools();
        $this->queue->flush();
        $this->assertHeartbeats(1, 'one after the list request');

        Heartbeat::forget(); // a second new memory
        $answer = $harness->callTool('get_more_tools', ['context' => 'a tool that sums']);
        $this->queue->flush();
        $this->assertObjectHasProperty('result', $answer);
        $this->assertHeartbeats(2, 'one after the get_more_tools call');
    }

    /** laravel/mcp builds a new server for each HTTP request; here the memory of the SDK is new for each one too. */
    public function testV18WithNoMemoryBetweenRequestsEachRequestGetsItsAnswerAndOnlyToolListsSend(): void
    {
        $harness = $this->harness(DemoServer::class);
        $answers = [];
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $request) {
            Heartbeat::forget(); // the memory of the request before is gone
            $answers[] = $request === 'call' ? $this->text($harness->callTool('alpha', [])) : count(JsonAssert::list($harness->listTools()->tools));
            $this->queue->flush();
        }

        $this->assertSame(['ok', 4, 'ok', 4, 'ok', 4], $answers);
        $this->assertHeartbeats(3);
    }

    /** V8 for laravel/mcp: a new server object for each request, in a memory that lasts, sends one heartbeat an hour. */
    public function testV8ANewServerObjectForEachRequestSendsOneHeartbeat(): void
    {
        $this->oldMemory();
        $harness = $this->harness(DemoServer::class);

        $harness->callTool('alpha', []);
        $harness->listTools();
        $harness->callTool('gamma', []);
        $this->queue->flush();

        $this->assertHeartbeats(1);
    }

    /**
     * Tracks each server of the harness as `track()` does.
     *
     * @param class-string<Server>  $server
     * @param array<string, class-string<Server\Contracts\Method>> $methods entries the customer adds after AgentCat tracked the server
     * @param array<string, class-string<Server\Contracts\Method>> $before  entries the customer adds before it
     */
    private function harness(string $server, Options $options = new Options(), array $methods = [], array $before = []): LaravelHarness
    {
        $publisher = new Publisher($this->queue);

        return new LaravelHarness($server, static function (Server $instance) use ($options, $publisher, $methods, $before): void {
            foreach ($before as $method => $handler) {
                $instance->addMethod($method, $handler);
            }
            LaravelMcpAdapter::install($instance, new TrackedServer(self::PROJECT, $options, LaravelMcpAdapter::serverInfo($instance), $publisher), $publisher, new CallInterceptor($publisher));
            foreach ($methods as $method => $handler) {
                $instance->addMethod($method, $handler);
            }
        });
    }

    /** A memory of 60 s, as the rows of the test table have unless they say otherwise. */
    private function oldMemory(): void
    {
        Guard::open(true, $this->now);
        $this->now += Guard::MEMORY_SECONDS;
    }

    /** The text the tool itself answered: the last text of the result, after what AgentCat adds before it. */
    private function text(\stdClass $answer): string
    {
        $this->assertObjectHasProperty('result', $answer);
        $texts = JsonAssert::column(JsonAssert::at($answer, 'result', 'content'), 'text');
        $text = end($texts);
        $this->assertIsString($text);

        return $text;
    }

    /** How many requests went to the side channel so far. */
    private function assertHeartbeats(int $count, string $message = ''): void
    {
        $this->assertCount($count, $this->channel->requests, $message);
    }

    private function heartbeat(int $index): HttpRequest
    {
        $heartbeats = $this->channel->requests;
        $this->assertArrayHasKey($index, $heartbeats, implode("\n", $this->logger->messages()));

        return $heartbeats[$index];
    }

    private function body(int $index): \stdClass
    {
        $body = json_decode($this->heartbeat($index)->body, false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $body);

        return $body;
    }
}
