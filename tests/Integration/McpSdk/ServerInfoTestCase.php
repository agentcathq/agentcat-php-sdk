<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Guard;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\McpSdk\Entry;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\ServerInfoRecorder;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\TestCase;

/** The server info heartbeat of an mcp/sdk server, from a real request to the body of the POST. */
abstract class ServerInfoTestCase extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Where the tests hand the server to AgentCat (D76). */
    abstract protected static function entry(): Entry;

    private MemoryLogger $logger;

    private RecordingExporter $exporter;

    private RecordingHttpChannel $channel;

    private EventQueue $queue;

    private float $now = 1000.0;

    #[\Override]
    protected function setUp(): void
    {
        ServerInfoRecorder::remove();
        Heartbeat::setClock(fn(): float => $this->now);
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->exporter = new RecordingExporter();
        $this->channel = new RecordingHttpChannel();
        $this->queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->exporter, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0); // a worker whose framework bridge ends each request in a window
    }

    #[\Override]
    protected function tearDown(): void
    {
        Heartbeat::setClock(null);
        ServerInfoRecorder::install();
        Log::reset();
    }

    public function testV1OneToolCallSendsWhatAClientGetsFromTheServer(): void
    {
        $this->oldMemory();
        $harness = $this->harness($this->demo());

        $answer = $harness->callTool('alpha', []);
        $this->assertSame('alpha ran', $this->text($answer));
        $this->assertHeartbeats(0, 'nothing is sent while the request is answered');
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(1);
        $this->assertStringEndsWith('/v1/public/server-info/', $this->heartbeat(0)->url);
        $body = $this->body(0);
        $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($body));
        $this->assertSame([self::PROJECT, 'demo-server', '1.2.3', 'Demo Title', 'Use the demo tools.'], [JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'server_version'), JsonAssert::at($body, 'server_title'), JsonAssert::at($body, 'instructions')]);
        $listed = $harness->listTools()->tools;
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], JsonAssert::column($listed, 'name'));
        $this->assertEquals($listed, JsonAssert::at($body, 'features', 'tools'), 'the tools are what a client gets from tools/list');
        $beta = JsonAssert::at($body, 'features', 'tools', 1);
        $this->assertSame([str_repeat('b', 2000), 'Beta Tool', true], [JsonAssert::at($beta, 'description'), JsonAssert::at($beta, 'title'), JsonAssert::at($beta, 'annotations', 'readOnlyHint')]);
        $this->assertObjectHasProperty('session_id', JsonAssert::object(JsonAssert::at($beta, 'inputSchema', 'properties')));
        $this->assertObjectHasProperty('sum', JsonAssert::object(JsonAssert::at($beta, 'outputSchema', 'properties')));
        $this->assertEquals(HttpHarness::result($harness->request('resources/list'))->resources, JsonAssert::at($body, 'features', 'resources'));
        $this->assertEquals(HttpHarness::result($harness->request('resources/templates/list'))->resourceTemplates, JsonAssert::at($body, 'features', 'resourceTemplates'));
        $this->assertEquals(HttpHarness::result($harness->request('prompts/list'))->prompts, JsonAssert::at($body, 'features', 'prompts'));
        $this->assertSame([2, 1, 2], [count(JsonAssert::list(JsonAssert::at($body, 'features', 'resources'))), count(JsonAssert::list(JsonAssert::at($body, 'features', 'resourceTemplates'))), count(JsonAssert::list(JsonAssert::at($body, 'features', 'prompts')))]);
        $this->assertSame('who', JsonAssert::at($body, 'features', 'prompts', 0, 'arguments', 0, 'name'));
    }

    public function testV2AToolListOnThreePagesArrivesWholeWithGetMoreToolsOnce(): void
    {
        $this->oldMemory();
        $builder = Server::builder()->setServerInfo('demo-server', '1.2.3')->setSession()->setPaginationLimit(2);
        foreach (['t1', 't2', 't3', 't4', 't5'] as $name) {
            $builder->addTool(static fn(): string => 'ok', $name);
        }
        $harness = $this->harness($builder);
        $this->assertObjectHasProperty('nextCursor', $harness->listTools(), 'the server pages its tool list');

        $harness->callTool('t1', []);
        $this->queue->endRequest(1.0);

        $this->assertSame(['t1', 't2', 't3', 't4', 't5', 'get_more_tools'], JsonAssert::column(JsonAssert::at($this->body(0), 'features', 'tools'), 'name'));
    }

    /** mcp/sdk always has a handler for each list, so a server with tools only answers the other lists with nothing. */
    public function testV3AServerWithToolsOnlyHasItsOtherListsEmpty(): void
    {
        $this->oldMemory();
        $harness = $this->harness(Server::builder()->setServerInfo('demo-server', '1.2.3')->setSession()->addTool(static fn(): string => 'ok', 'alpha'));

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $features = JsonAssert::at($this->body(0), 'features');
        $this->assertSame(['tools', 'resources', 'resourceTemplates', 'prompts'], JsonAssert::keys($features));
        $this->assertSame([[], [], []], [JsonAssert::at($features, 'resources'), JsonAssert::at($features, 'resourceTemplates'), JsonAssert::at($features, 'prompts')]);
        $this->assertSame('', JsonAssert::at($this->body(0), 'server_title'));
        $this->assertSame('', JsonAssert::at($this->body(0), 'instructions'));
    }

    public function testV4AFailingPromptListHandlerLeavesOnlyItsKeyOut(): void
    {
        $this->oldMemory();
        $harness = $this->harness($this->demo()->addRequestHandler(new CustomerHandler(ListPromptsRequest::class, static fn(): never => throw new \RuntimeException('no prompts today'))));

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $features = JsonAssert::at($this->body(0), 'features');
        $this->assertSame(['tools', 'resources', 'resourceTemplates'], JsonAssert::keys($features));
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], JsonAssert::column(JsonAssert::at($features, 'tools'), 'name'));
        $this->assertStringContainsString('| without prompts (RuntimeException)', implode("\n", $this->logger->messages()));
    }

    public function testV5AFailingToolListHandlerSendsNothing(): void
    {
        $this->oldMemory();
        $harness = $this->harness($this->demo()->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static fn(): never => throw new \RuntimeException('no tools today'))));

        $answer = $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $this->assertSame('alpha ran', $this->text($answer));
        $this->assertHeartbeats(0);
        $this->assertContains('Skipped the server info heartbeat: the tool list failed - RuntimeException', $this->logger->messages());
    }

    public function testV6AListHandlerThatGivesTheSameCursorEachTimeFailsItsList(): void
    {
        $this->oldMemory();
        $handler = new CustomerHandler(ListResourcesRequest::class, static fn(): ListResourcesResult => new ListResourcesResult([], 'again'));
        $harness = $this->harness($this->demo()->addRequestHandler($handler));
        $started = microtime(true);

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertSame(2, $handler->calls, 'the second page gave the cursor of the first again');
        $this->assertObjectNotHasProperty('resources', JsonAssert::object(JsonAssert::at($this->body(0), 'features')));
        $this->assertObjectHasProperty('prompts', JsonAssert::object(JsonAssert::at($this->body(0), 'features')));
    }

    public function testV10AFailingOrHangingListHandlerOrApiNeverReachesTheToolCall(): void
    {
        $this->oldMemory();
        $this->expectOutputString('');
        $this->channel->complete = false; // the API never answers
        $lists = new CustomerHandler(ListResourcesRequest::class, static fn(): never => throw new \RuntimeException('a handler that would hang or fail'));
        $harness = $this->harness($this->demo()->addRequestHandler($lists));

        $answer = $harness->callTool('alpha', []);

        $this->assertSame('alpha ran', $this->text($answer));
        $this->assertSame(0, $lists->calls, 'no list handler runs while the request is answered');
        $this->assertHeartbeats(0);

        $this->assertFalse($this->queue->endRequest(1.0));
        $this->assertSame(1, $lists->calls);
        $this->assertHeartbeats(1);
        $this->assertSame('gamma ran', $this->text($harness->callTool('gamma', [])), 'the next call is answered while the heartbeat is still on its way');
    }

    public function testV11TheRedactionFunctionRunsOnTheTextAndNotOnTheNames(): void
    {
        $this->oldMemory();
        $builder = Server::builder()->setServerInfo('secret-server', '1.2.3', title: 'A secret title')->setInstructions('the secret plan')->setSession()
            ->addTool(static fn(): string => 'ok', 'secret_tool', description: 'secret tool', inputSchema: ['type' => 'object', 'properties' => ['p' => ['type' => 'string', 'description' => 'a secret']]])
            ->addResource(static fn(): string => 'x', 'file:///secret.txt', 'secret_file', description: 'the secret file');
        $harness = $this->harness($builder, new Options(redactSensitiveInformation: static fn(string $text): string => str_replace('secret', '[x]', $text)));

        $harness->callTool('secret_tool', []);
        $this->queue->endRequest(1.0);

        $body = $this->body(0);
        $this->assertSame(['the [x] plan', 'A [x] title', 'secret-server'], [JsonAssert::at($body, 'instructions'), JsonAssert::at($body, 'server_title'), JsonAssert::at($body, 'server_name')]);
        $tool = JsonAssert::at($body, 'features', 'tools', 0);
        $this->assertSame(['secret_tool', '[x] tool', 'a [x]'], [JsonAssert::at($tool, 'name'), JsonAssert::at($tool, 'description'), JsonAssert::at($tool, 'inputSchema', 'properties', 'p', 'description')]);
        $resource = JsonAssert::at($body, 'features', 'resources', 0);
        $this->assertSame(['file:///secret.txt', 'secret_file', 'the [x] file'], [JsonAssert::at($resource, 'uri'), JsonAssert::at($resource, 'name'), JsonAssert::at($resource, 'description')]);
    }

    public function testV12AFailingRedactionFunctionSendsNothing(): void
    {
        $this->oldMemory();
        $harness = $this->harness($this->demo(), new Options(redactSensitiveInformation: static fn(string $text): string => throw new \DomainException('redactor down')));

        $answer = $harness->callTool('get_more_tools', ['context' => 'a tool that sums']);
        $this->queue->endRequest(1.0);

        $this->assertObjectHasProperty('result', $answer);
        $this->assertHeartbeats(0);
        $this->assertContains('Skipped the server info heartbeat: redactSensitiveInformation failed - DomainException', $this->logger->messages());
    }

    public function testV15TheHeartbeatNamesTheServerAsTheEventOfTheCallDoes(): void
    {
        $this->oldMemory();
        $harness = $this->harness($this->demo());

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $this->assertCount(1, $this->exporter->events);
        $event = $this->exporter->events[0];
        $this->assertSame(['demo-server', '1.2.3'], [$event->serverName, $event->serverVersion]);
        $this->assertSame([$event->serverName, $event->serverVersion, $event->projectId], [JsonAssert::at($this->body(0), 'server_name'), JsonAssert::at($this->body(0), 'server_version'), JsonAssert::at($this->body(0), 'project_id')]);
    }

    public function testV17InANewMemoryOnlyAToolListRequestAndAFeedbackNoteSend(): void
    {
        $harness = $this->harness($this->demo()); // the handshake and the memory are new

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(0, 'none after the tool call');

        $harness->listTools();
        $this->queue->endRequest(1.0);
        // After build() the library answers tools/list alone: AgentCat has no check there.
        $this->assertHeartbeats(static::entry()->afterBuild() ? 0 : 1);

        Heartbeat::forget(); // a second new memory
        $this->channel->requests = [];
        $answer = $harness->callTool('get_more_tools', ['context' => 'a tool that sums']);
        $this->queue->endRequest(1.0);
        $this->assertObjectHasProperty('result', $answer);
        $this->assertHeartbeats(1, 'one after the get_more_tools call');
    }

    public function testV18WithNoMemoryBetweenRequestsEachRequestGetsItsAnswerAndOnlyToolListsSend(): void
    {
        $harness = $this->harness($this->demo());
        $answers = [];
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $request) {
            Heartbeat::forget(); // the memory of the request before is gone
            $answers[] = $request === 'call'
                ? $this->text($harness->callTool('alpha', []))
                : count(JsonAssert::list($harness->listTools()->tools));
            $this->queue->endRequest(1.0);
        }

        $this->assertSame(['alpha ran', 4, 'alpha ran', 4, 'alpha ran', 4], $answers);
        // After build() the library answers tools/list alone: AgentCat has no check there.
        $this->assertHeartbeats(static::entry()->afterBuild() ? 0 : 3);
    }

    /** A stdio server and a plain worker loop: each hook pumps, and nothing ends a request in a window. */
    public function testAProcessWithoutARequestEndWindowRunsNoListHandlerInAnyPump(): void
    {
        $this->queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->exporter, channel: $this->channel, finishesRequests: false);
        $lists = new CustomerHandler(ListResourcesRequest::class, static fn(): ListResourcesResult => new ListResourcesResult([]));
        $harness = $this->harness(self::demo()->addRequestHandler($lists));

        for ($request = 0; $request < 3; ++$request) {
            $harness->listTools();
            $harness->callTool('get_more_tools', ['context' => 'a tool that sums']);
            $harness->callTool('alpha', []);
            $this->now += Guard::INTERVAL_SECONDS;
        }
        $this->queue->flush();
        $this->queue->shutdown();

        $this->assertSame(0, $lists->calls);
        $this->assertHeartbeats(0);
        $this->assertSame([], array_values(array_filter($this->logger->messages(), static fn(string $line): bool => str_contains($line, 'heartbeat'))), 'decided once: no line for each request');
    }

    public function testAFlushOfTheCustomerInsideAToolOrBeforeTheResponseRunsNoListHandler(): void
    {
        $this->oldMemory();
        $transport = new RecordingTransport();
        $this->queue = new EventQueue(transport: $transport, exporters: $this->exporter, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0);
        $lists = new CustomerHandler(ListResourcesRequest::class, static fn(): ListResourcesResult => new ListResourcesResult([]));
        $queue = $this->queue;
        $builder = self::demo()->addRequestHandler($lists)->addTool(static function () use ($queue): string {
            $queue->flush();

            return 'flushed';
        }, 'flushing');
        $harness = $this->harness($builder);
        $harness->callTool('get_more_tools', ['context' => 'a tool that sums']); // the heartbeat of this request is pending now

        $this->assertSame('flushed', self::text($harness->callTool('flushing', [])), 'a flush inside a tool');
        $this->assertTrue($this->queue->flush(), 'a flush between the handler and the response');
        $this->assertTrue($this->queue->flush(1.0));

        $this->assertSame(0, $lists->calls);
        $this->assertHeartbeats(0);
        $this->assertEqualsWithDelta(0.0, $transport->now(), PHP_FLOAT_EPSILON, 'no flush waited: the time is what it is without a heartbeat');
    }

    public function testAJsonRpcBatchRunsNoListHandlerBeforeItsResponse(): void
    {
        $this->oldMemory();
        $lists = new CustomerHandler(ListResourcesRequest::class, static fn(): ListResourcesResult => new ListResourcesResult([]));
        $harness = $this->harness(self::demo()->addRequestHandler($lists));

        $answer = $harness->batch([
            ['tools/call', HttpHarness::callParams('get_more_tools', ['context' => 'a tool that sums'], null)],
            ['tools/list', []],
            ['tools/call', HttpHarness::callParams('alpha', [], null)],
        ]);

        $this->assertNotSame('', $answer);
        $this->assertSame(0, $lists->calls, 'the response of the batch is ready, and no list handler ran for the heartbeat');
        $this->assertHeartbeats(0);
    }

    public function testAWorkerWhoseWindowEndsWithAnEventInFlightDropsTheHeartbeatAndTheNextRequestRunsNoListHandler(): void
    {
        $this->oldMemory();
        $transport = new RecordingTransport(complete: false); // the event of the call is not delivered inside the window
        $this->queue = new EventQueue(transport: $transport, exporters: $this->exporter, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0);
        $lists = new CustomerHandler(ListResourcesRequest::class, static fn(): ListResourcesResult => new ListResourcesResult([]));
        $harness = $this->harness(self::demo()->addRequestHandler($lists));

        $harness->callTool('alpha', []);
        $this->assertFalse($this->queue->endRequest(1.0));
        $this->assertContains('Skipped the server info heartbeat: the request-end window ended before the events were delivered', $this->logger->messages());

        $transport->complete = true;
        $this->assertSame('gamma ran', self::text($harness->callTool('gamma', [])), 'the next request');
        $this->queue->endRequest(1.0);

        $this->assertSame(0, $lists->calls);
        $this->assertHeartbeats(0);
    }

    public function testNoLogLineHoldsTheMessageOfAListHandlerOrOfTheRedactionFunction(): void
    {
        $this->oldMemory();
        $diagnostics = [];
        Log::setDiagnosticsSink(static function (string $line) use (&$diagnostics): void {
            $diagnostics[] = $line;
        });
        $prompts = new CustomerHandler(ListPromptsRequest::class, static fn(): never => throw new \RuntimeException('the secret of the list handler'));
        $harness = $this->harness(self::demo()->addRequestHandler($prompts), new Options(redactSensitiveInformation: static fn(string $text): string => throw new \DomainException('the secret of the redactor: ' . $text)));

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $this->assertSame(1, $prompts->calls);
        $this->assertHeartbeats(0);
        $this->assertContains('Skipped the server info heartbeat: redactSensitiveInformation failed - DomainException', $this->logger->messages());
        $heartbeat = static fn(string $line): bool => str_contains($line, 'server info heartbeat');
        $this->assertStringNotContainsString('secret', implode("\n", array_filter($this->logger->messages(), $heartbeat)));
        $this->assertNotSame([], array_filter($diagnostics, $heartbeat));
        $this->assertStringNotContainsString('secret', implode("\n", array_filter($diagnostics, $heartbeat)));
    }

    /** V8 with real servers: the guard's key holds the server name, so two names of one version both send. */
    public function testV8TwoServersOfOneVersionAndTwoNamesBothSend(): void
    {
        $this->oldMemory();
        $server = static fn(string $name): Builder => Server::builder()->setServerInfo($name, '1.0.0')->setSession()->addTool(static fn(): string => 'ok', 'alpha');
        $one = $this->harness($server('one-server'));
        $two = $this->harness($server('two-server'));

        $one->callTool('alpha', []);
        $two->callTool('alpha', []);
        $one->callTool('alpha', []);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(2);

        Heartbeat::forget();
        $this->channel->requests = [];
        $one->listTools();
        $two->listTools();
        $this->queue->endRequest(1.0);
        // After build() the library answers tools/list alone: AgentCat has no check there.
        $this->assertHeartbeats(static::entry()->afterBuild() ? 0 : 2);
    }

    public function testAListHandlerGetsTheSessionOfTheRequestThatStartedTheHeartbeat(): void
    {
        $this->oldMemory();
        $handler = new class implements Server\Handler\Request\RequestHandlerInterface {
            /** @var list<string> */
            public array $sessions = [];

            #[\Override]
            public function supports(Request $request): bool
            {
                return $request instanceof ListPromptsRequest;
            }

            #[\Override]
            public function handle(Request $request, Server\Session\SessionInterface $session): \Mcp\Schema\JsonRpc\Response
            {
                $this->sessions[] = $session->getId()->toRfc4122();

                return new \Mcp\Schema\JsonRpc\Response($request->getId(), new \Mcp\Schema\Result\ListPromptsResult([]));
            }
        };
        $harness = $this->harness($this->demo()->addRequestHandler($handler));

        $harness->callTool('alpha', []);
        $this->queue->endRequest(1.0);

        $this->assertSame([$harness->sessionId()], $handler->sessions);
    }

    /** The text the tool itself answered: the last text of the result, after what AgentCat adds before it. */
    private function text(\stdClass $answer): string
    {
        $texts = JsonAssert::column(HttpHarness::result($answer)->content, 'text');
        $text = end($texts);
        $this->assertIsString($text);

        return $text;
    }

    /** The server of the test table: three tools, two resources, one resource template and two prompts. */
    private function demo(): Builder
    {
        return Server::builder()
            ->setServerInfo('demo-server', '1.2.3', title: 'Demo Title')
            ->setInstructions('Use the demo tools.')
            ->setSession()
            ->addTool(static fn(): string => 'alpha ran', 'alpha', description: 'The first tool')
            ->addTool(
                static fn(int $n): array => ['sum' => $n],
                'beta',
                'Beta Tool',
                str_repeat('b', 2000),
                new ToolAnnotations(readOnlyHint: true),
                ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']],
                outputSchema: ['type' => 'object', 'properties' => ['sum' => ['type' => 'integer']]],
            )
            ->addTool(static fn(): string => 'gamma ran', 'gamma', description: 'The third tool')
            ->addResource(static fn(): string => 'a', 'file:///a.txt', 'a', description: 'File a')
            ->addResource(static fn(): string => 'b', 'file:///b.txt', 'b')
            ->addResourceTemplate(static fn(string $name): string => $name, 'file:///{name}.txt', 'files', description: 'Any file')
            ->addPrompt(static fn(string $who): string => 'Hello ' . $who, 'greet', description: 'Greets')
            ->addPrompt(static fn(): string => 'Bye', 'bye');
    }

    /** Tracks the Builder as the entry does, builds the server and runs the handshake. */
    private function harness(Builder $builder, Options $options = new Options()): HttpHarness
    {
        $publisher = new Publisher($this->queue);
        static::entry()->track($builder, new TrackedServer(self::PROJECT, $options, McpSdkAdapter::serverInfo($builder), $publisher), $publisher);
        $harness = new HttpHarness(static::entry()->build($builder));
        $harness->initialize();

        return $harness;
    }

    /** A memory of 60 s, as the rows of the test table have unless they say otherwise. */
    private function oldMemory(): void
    {
        Guard::open(true, $this->now);
        $this->now += Guard::MEMORY_SECONDS;
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
