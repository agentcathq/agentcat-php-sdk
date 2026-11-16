<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\ServerInfo\Guard;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Internal\ServerInfo\PendingHeartbeat;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Options;
use AgentCat\Tests\Support\FakeServerInfo;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\ServerInfoRecorder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The heartbeat from a check to its request, over a fake server, a fake clock and a queue that records. */
final class HeartbeatTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private MemoryLogger $logger;

    private RecordingTransport $transport;

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
        $this->transport = new RecordingTransport();
        $this->channel = new RecordingHttpChannel();
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Heartbeat::setClock(null);
        ServerInfoRecorder::install();
        Log::reset();
    }

    public function testV1OneToolCallSendsOnePostWithTheWholeServer(): void
    {
        $this->oldMemory();
        $source = new FakeServerInfo();

        $this->toolCall($this->server(), source: $source);
        $this->assertSame([], $source->asked, 'the check reads no list');
        $this->assertHeartbeats(0);
        $this->queue->pump();

        $this->assertHeartbeats(1);
        $body = $this->body(0);
        $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($body));
        $this->assertSame([self::PROJECT, 'demo-server', '1.2.3', 'Demo Title', 'Use the demo tools.'], [JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'server_version'), JsonAssert::at($body, 'server_title'), JsonAssert::at($body, 'instructions')]);
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], JsonAssert::column(JsonAssert::at($body, 'features', 'tools'), 'name'));
        $this->assertSame(str_repeat('b', 2000), JsonAssert::at($body, 'features', 'tools', 1, 'description'));
        $this->assertSame([2, 1, 2], [count(JsonAssert::list(JsonAssert::at($body, 'features', 'resources'))), count(JsonAssert::list(JsonAssert::at($body, 'features', 'resourceTemplates'))), count(JsonAssert::list(JsonAssert::at($body, 'features', 'prompts')))]);
        $this->assertSame([sprintf('Sent server info heartbeat | 4 tools | %d bytes', strlen($this->heartbeat(0)->body))], $this->logger->messages());
    }

    public function testV7OneHeartbeatAnHourForEachProcess(): void
    {
        $this->oldMemory();
        $server = $this->server();

        $this->toolCall($server);
        $this->toolList($server);
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(1, 'three requests: one heartbeat');

        $this->now += 3599.0;
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(1, 'none at 3,599 s');

        $this->now += 1.0;
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(2, 'the second one at 3,600 s');
    }

    public function testV7AFailedAttemptAlsoWaitsOneHour(): void
    {
        $this->oldMemory();
        $server = $this->server();
        $failing = FakeServerInfo::demo();
        $failing[ServerInfoSource::TOOLS] = new \RuntimeException('down');

        $this->toolCall($server, source: new FakeServerInfo($failing));
        $this->queue->pump();
        $this->now += 3599.0;
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(0);

        $this->now += 1.0;
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(1);
    }

    public function testV8TwoServerObjectsOfOneNameSendOneHeartbeatAndAnotherNameSendsItsOwn(): void
    {
        $this->oldMemory();

        $this->toolCall($this->server());
        $this->toolCall($this->server()); // a new server object for each request
        $this->queue->pump();
        $this->assertHeartbeats(1);

        $this->toolCall($this->server('other-server'));
        $this->queue->pump();
        $this->assertHeartbeats(2);
        $this->assertSame(['demo-server', 'other-server'], [JsonAssert::at($this->body(0), 'server_name'), JsonAssert::at($this->body(1), 'server_name')]);

        $this->toolCall($this->server(project: 'proj_bbbbbbbbbbbbbbbbbbbbbbbbbbb'));
        $this->queue->pump();
        $this->assertHeartbeats(3, 'the same name in another project');
    }

    /** @return iterable<string, array{string, Options, bool}> */
    public static function serversWithoutAHeartbeat(): iterable
    {
        yield 'an empty project ID' => ['', new Options(), true];
        yield 'tracing off' => [self::PROJECT, new Options(enableTracing: false), true];
        yield 'a client that sends nothing' => [self::PROJECT, new Options(), false];
    }

    #[DataProvider('serversWithoutAHeartbeat')]
    public function testV9NoHeartbeatWithoutAProjectOrWithTracingOff(string $project, Options $options, bool $reports): void
    {
        $this->oldMemory();
        $source = new FakeServerInfo();
        $server = new TrackedServer($project, $options, static fn(): array => ['demo-server', '1.2.3'], new Publisher($this->queue), reportsServerInfo: $reports);

        $this->toolCall($server, source: $source);
        $this->toolList($server, $source);
        $this->toolCall($server, 'get_more_tools', $source);
        $this->assertFalse($this->queue->hasWork());
        $this->queue->flush();

        $this->assertHeartbeats(0);
        $this->assertSame([], $source->asked);
        $this->assertSame([], $this->logger->messages());
    }

    public function testV10AnApiThatNeverAnswersKeepsNoRequestWaiting(): void
    {
        $this->oldMemory();
        $this->channel->complete = false;

        $this->toolCall($this->server());
        $this->queue->pump();
        $this->queue->pump();

        $this->assertHeartbeats(1);
        $this->assertSame(0, $this->transport->waits, 'no pass waited');
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{array<string, array<string, array{list<mixed>, ?string}>|\Throwable>, (\Closure(string): string)|null, string}> */
    public static function failures(): iterable
    {
        $tools = FakeServerInfo::demo();
        $tools[ServerInfoSource::TOOLS] = new \RuntimeException('the handler failed');
        yield 'a list handler fails' => [$tools, null, 'Skipped the server info heartbeat: the tool list failed - RuntimeException: the handler failed'];
        $error = FakeServerInfo::demo();
        $error[ServerInfoSource::TOOLS] = new \TypeError('not a handler');
        yield 'a list handler fails with an Error' => [$error, null, 'Skipped the server info heartbeat: the tool list failed - TypeError: not a handler'];
        yield 'the redaction function fails' => [FakeServerInfo::demo(), static fn(string $text): string => throw new \DomainException('redactor down'), 'Skipped the server info heartbeat: redactSensitiveInformation failed - DomainException: redactor down'];
    }

    /**
     * @param array<string, array<string, array{list<mixed>, ?string}>|\Throwable> $lists
     * @param (\Closure(string): string)|null $redactor
     */
    #[DataProvider('failures')]
    public function testV10AndV12AFailureSendsNothingAndStaysInside(array $lists, ?\Closure $redactor, string $line): void
    {
        $this->oldMemory();
        $this->expectOutputString('');

        $this->toolCall($this->server(options: new Options(redactSensitiveInformation: $redactor)), source: new FakeServerInfo($lists));
        $this->queue->pump();
        $this->queue->flush();

        $this->assertHeartbeats(0);
        $this->assertSame([$line], $this->logger->messages());
    }

    public function testV10After500NoSecondPostFollows(): void
    {
        $this->oldMemory();
        $this->channel = new RecordingHttpChannel(default: new HttpResponse(500, 'Internal Server Error', ''));
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: true);
        $server = $this->server();

        $this->toolCall($server);
        $this->queue->pump();
        $this->toolCall($server);
        $this->queue->pump();
        $this->queue->flush();

        $this->assertHeartbeats(1);
        $this->assertSame(['Server info heartbeat failed: HTTP 500'], $this->logger->messages());
    }

    public function testV10AWarningOfAListHandlerOrOfTheRedactorReachesNoErrorHandler(): void
    {
        $this->oldMemory();
        $seen = [];
        set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::PROMPTS] = static function (): array {
            trigger_error('a notice of the handler', E_USER_NOTICE);
            trigger_error('a warning of the handler', E_USER_WARNING);

            return [[], null];
        };
        $redactor = static function (string $text): string {
            trigger_error('a deprecation of the redactor', E_USER_DEPRECATED);

            return $text;
        };

        try {
            $this->toolCall($this->server(options: new Options(redactSensitiveInformation: $redactor)), source: new FakeServerInfo($lists));
            $this->queue->pump();
            trigger_error('after the run', E_USER_NOTICE);
        } finally {
            restore_error_handler();
        }

        $this->assertHeartbeats(1);
        $this->assertSame(['after the run'], $seen, 'the handler of the application is back after the run');
    }

    public function testV10ASourceThatCannotBeMadeCostsOnlyTheHeartbeat(): void
    {
        $this->oldMemory();

        Heartbeat::onToolList($this->server(), static fn(): ServerInfoSource => throw new \LogicException('the request has no session'));
        $this->queue->flush();

        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: the check failed - LogicException'], $this->logger->messages());
    }

    public function testV11TheHeartbeatCarriesRedactedTextAndTheNamesAsTheyAre(): void
    {
        $this->oldMemory();
        $lists = [
            ServerInfoSource::TOOLS => ['' => [[(object) ['name' => 'secret_tool', 'description' => 'secret tool', 'inputSchema' => (object) ['type' => 'object', 'properties' => (object) ['p' => (object) ['type' => 'string', 'description' => 'a secret']]]]], null]],
            ServerInfoSource::RESOURCES => ['' => [[(object) ['uri' => 'file:///secret.txt', 'name' => 'secret']], null]],
        ];
        $options = new Options(enableReportMissing: false, redactSensitiveInformation: static fn(string $text): string => str_replace('secret', '[x]', $text));

        $this->toolCall($this->server('secret-server', $options), source: new FakeServerInfo($lists, 'Secret Title', 'the secret plan'));
        $this->queue->pump();

        $body = $this->body(0);
        $this->assertSame('the [x] plan', JsonAssert::at($body, 'instructions'));
        $tool = JsonAssert::at($body, 'features', 'tools', 0);
        $this->assertSame(['secret_tool', '[x] tool', 'a [x]'], [JsonAssert::at($tool, 'name'), JsonAssert::at($tool, 'description'), JsonAssert::at($tool, 'inputSchema', 'properties', 'p', 'description')]);
        $this->assertSame(['file:///secret.txt', 'secret'], [JsonAssert::at($body, 'features', 'resources', 0, 'uri'), JsonAssert::at($body, 'features', 'resources', 0, 'name')]);
        $this->assertSame('secret-server', JsonAssert::at($body, 'server_name'));
    }

    public function testV12ARedactorThatGivesNoStringSendsNothing(): void
    {
        $this->oldMemory();
        // @phpstan-ignore argument.type (a customer callback can return any type at runtime)
        $options = new Options(redactSensitiveInformation: static fn(string $text): int => 42);

        $this->toolCall($this->server(options: $options));
        $this->queue->flush();

        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: redactSensitiveInformation must return a string, got int'], $this->logger->messages());
    }

    public function testV13After413TheQueueSendsTheSmallerBodyAndStopsAfterASecond413(): void
    {
        $this->oldMemory();
        $this->channel = new RecordingHttpChannel([new HttpResponse(413, 'Payload Too Large', ''), new HttpResponse(202, 'Accepted', '')]);
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: true);

        $this->toolCall($this->server());
        $this->assertTrue($this->queue->flush());

        $this->assertHeartbeats(2);
        $this->assertObjectHasProperty('inputSchema', JsonAssert::object(JsonAssert::at($this->body(0), 'features', 'tools', 0)));
        foreach (JsonAssert::list(JsonAssert::at($this->body(1), 'features', 'tools')) as $index => $tool) {
            $this->assertObjectNotHasProperty('inputSchema', JsonAssert::object($tool));
            $this->assertObjectNotHasProperty('outputSchema', JsonAssert::object($tool));
            $full = JsonAssert::at($this->body(0), 'features', 'tools', $index);
            $this->assertSame([JsonAssert::at($full, 'name'), JsonAssert::optional($full, 'title'), JsonAssert::at($full, 'description')], [JsonAssert::at($tool, 'name'), JsonAssert::optional($tool, 'title'), JsonAssert::at($tool, 'description')]);
        }

        $this->channel = new RecordingHttpChannel(default: new HttpResponse(413, 'Payload Too Large', ''));
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: true);
        $this->toolCall($this->server('other-server'));
        $this->assertTrue($this->queue->flush());
        $this->queue->flush();
        $this->assertHeartbeats(2, '413 two times: two posts, no third');
    }

    public function testV14ACustomApiBaseUrlGetsThePost(): void
    {
        $this->oldMemory();
        $this->queue->configure('https://custom.example.test/base/');

        $this->toolCall($this->server());
        $this->queue->pump();

        $this->assertSame(['https://custom.example.test/base/v1/public/server-info/'], $this->channel->urls());
    }

    public function testV17ANewMemorySendsForAToolListAndForAFeedbackNoteOnly(): void
    {
        $server = $this->server();

        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(0, 'none after the tool call');
        $this->assertFalse($this->queue->hasWork());

        $this->toolList($server);
        $this->queue->pump();
        $this->assertHeartbeats(1, 'one after the list request');

        Heartbeat::forget(); // a second new memory
        $this->toolCall($server, 'get_more_tools');
        $this->queue->pump();
        $this->assertHeartbeats(2, 'one after the get_more_tools call');
    }

    public function testV17WithReportMissingOffGetMoreToolsIsAToolCallLikeAnyOther(): void
    {
        $this->toolCall($this->server(options: new Options(enableReportMissing: false)), 'get_more_tools');
        $this->queue->pump();

        $this->assertHeartbeats(0);
    }

    public function testV18WithNoMemoryBetweenRequestsEachToolListSendsAndNoToolCallDoes(): void
    {
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $request) {
            Heartbeat::forget(); // the memory of the request before is gone
            $server = $this->server(); // and each request tracks a new server
            $request === 'call' ? $this->toolCall($server) : $this->toolList($server);
            $this->queue->pump();
        }

        $this->assertHeartbeats(3);
    }

    public function testV19AToolCallSendsOnlyInAMemoryOfSixtySeconds(): void
    {
        $server = $this->server();

        $this->toolCall($server); // an age of 0 s
        $this->queue->pump();
        $this->now += 59.0;
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(0, 'none at 0 s and at 59 s');

        $this->now += 1.0;
        $this->toolCall($server);
        $this->queue->pump();
        $this->assertHeartbeats(1, 'one at 60 s');
    }

    public function testAServerWhoseNameIsUnknownSendsNothing(): void
    {
        $this->oldMemory();
        $server = new TrackedServer(self::PROJECT, new Options(), static fn(): array => [null, null], new Publisher($this->queue));
        $empty = new TrackedServer(self::PROJECT, new Options(), static fn(): array => ['', '1'], new Publisher($this->queue));

        $this->toolCall($server);
        $this->queue->pump();
        $this->now += Guard::INTERVAL_SECONDS;
        $this->toolCall($empty);
        $this->queue->pump();

        $this->assertHeartbeats(0);
        $this->assertSame(array_fill(0, 2, 'Skipped the server info heartbeat: the server name is unknown'), $this->logger->messages());
    }

    public function testTheBuildStopsAfterThirtySecondsAndSendsNothing(): void
    {
        $this->oldMemory();
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::RESOURCES] = function (): array {
            $this->now += PendingHeartbeat::BUILD_SECONDS; // one page that takes 30 s

            return [[], null];
        };

        $this->toolCall($this->server(), source: $source = new FakeServerInfo($lists));
        $this->queue->pump();

        $this->assertHeartbeats(0);
        $this->assertNotContains('prompts@', $source->asked, 'no page was asked for after the limit');
        $this->assertSame(['Skipped the server info heartbeat: reading and redacting the lists took more than 30 s'], $this->logger->messages());
    }

    public function testARedactionThatPassesTheBuildLimitSendsNothing(): void
    {
        $this->oldMemory();
        $calls = 0;
        $redactor = function (string $text) use (&$calls): string {
            ++$calls;
            $this->now += 20.0;

            return $text;
        };

        $this->toolCall($this->server(options: new Options(redactSensitiveInformation: $redactor)));
        $this->queue->pump();

        $this->assertSame(2, $calls, 'the limit is checked before each call');
        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: reading and redacting the lists took more than 30 s'], $this->logger->messages());
    }

    public function testALastRedactionThatPassesTheBuildLimitSendsNothing(): void
    {
        $this->oldMemory();
        $redactor = function (string $text): string {
            $this->now += $text === 'Whom to greet' ? 31.0 : 0.0; // the last text of the demo server

            return $text;
        };

        $this->toolCall($this->server(options: new Options(redactSensitiveInformation: $redactor)));
        $this->queue->pump();

        $this->assertHeartbeats(0);
    }

    public function testInADeliveryWithAWindowTheBuildStopsWhenTheWindowIsOver(): void
    {
        $this->oldMemory();
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::TOOLS] = function (): array {
            $this->now += 1.5; // slower than the 1 s window of a worker

            return [[FakeServerInfo::tool('alpha')], null];
        };

        $this->toolCall($this->server(), source: $source = new FakeServerInfo($lists));
        $this->assertTrue($this->queue->flush(1.0));

        $this->assertSame(['tools@'], $source->asked, 'the page that ran could not be stopped; no other page was asked for');
        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: the delivery time ended before the lists were read'], $this->logger->messages());
    }

    public function testAPendingHeartbeatDoesNotMakeAWorkersRequestEndWaitLonger(): void
    {
        $this->oldMemory();
        $this->channel->complete = false; // the API never answers the heartbeat
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: self::PROJECT, eventType: 'mcp:tools/call')));

        $this->toolCall($this->server());
        $this->assertFalse($this->queue->flush(1.0));

        $this->assertCount(1, $this->transport->deliveries, 'the event went first');
        $this->assertHeartbeats(1);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the wait ended with the 1 s window of the event send');
    }

    public function testAPendingHeartbeatDoesNotMoveTheShutdownDeadlineOfAPerRequestSapi(): void
    {
        $this->oldMemory();
        $this->channel->complete = false;
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: false);
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: self::PROJECT, eventType: 'mcp:tools/call')));

        $this->toolCall($this->server());
        $this->queue->pump();
        $this->queue->flush(0.0);
        $this->assertHeartbeats(0, 'nothing before the response is out');
        $this->queue->responseSent();
        $this->queue->finalShutdown();

        $this->assertHeartbeats(1);
        $this->assertSame(EventQueue::SHUTDOWN_TIMEOUT_SECONDS, $this->transport->now());
    }

    public function testWhereTheClientDoesNotGetItsResponseFirstNoHeartbeatIsSent(): void
    {
        $this->oldMemory();
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: false);

        $this->toolCall($this->server(), source: $source = new FakeServerInfo());
        $this->queue->finalShutdown();

        $this->assertSame([], $source->asked);
        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: the client does not have its response yet'], $this->logger->messages());
    }

    public function testTheProjectIsReadFromTheServerAndTheVersionCanBeUnknown(): void
    {
        $this->oldMemory();
        $server = new TrackedServer(self::PROJECT, new Options(), static fn(): array => ['demo-server', null], new Publisher($this->queue));

        $this->toolCall($server);
        $this->queue->pump();

        $this->assertObjectNotHasProperty('server_version', $this->body(0));
        $this->assertSame(self::PROJECT, JsonAssert::at($this->body(0), 'project_id'));
    }

    /** A memory of 60 s, as the rows of the test table have unless they say otherwise. */
    private function oldMemory(): void
    {
        Guard::open(true, $this->now);
        $this->now += Guard::MEMORY_SECONDS;
    }

    private function server(string $name = 'demo-server', Options $options = new Options(), string $project = self::PROJECT): TrackedServer
    {
        return new TrackedServer($project, $options, static fn(): array => [$name, '1.2.3'], new Publisher($this->queue));
    }

    private function toolCall(TrackedServer $server, string $tool = 'alpha', ?FakeServerInfo $source = null): void
    {
        Heartbeat::onToolCall($server, $tool, static fn(): FakeServerInfo => $source ?? new FakeServerInfo());
    }

    private function toolList(TrackedServer $server, ?FakeServerInfo $source = null): void
    {
        Heartbeat::onToolList($server, static fn(): FakeServerInfo => $source ?? new FakeServerInfo());
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
