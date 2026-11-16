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
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0); // a worker whose bridge ends each request in a window
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
        $this->queue->endRequest(1.0);

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
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1, 'three requests: one heartbeat');

        $this->now += 3599.0;
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1, 'none at 3,599 s');

        $this->now += 1.0;
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(2, 'the second one at 3,600 s');
    }

    public function testV7AFailedAttemptAlsoWaitsOneHour(): void
    {
        $this->oldMemory();
        $server = $this->server();
        $failing = FakeServerInfo::demo();
        $failing[ServerInfoSource::TOOLS] = new \RuntimeException('down');

        $this->toolCall($server, source: new FakeServerInfo($failing));
        $this->queue->endRequest(1.0);
        $this->now += 3599.0;
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(0);

        $this->now += 1.0;
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1);
    }

    public function testV8TwoServerObjectsOfOneNameSendOneHeartbeatAndAnotherNameSendsItsOwn(): void
    {
        $this->oldMemory();

        $this->toolCall($this->server());
        $this->toolCall($this->server()); // a new server object for each request
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1);

        $this->toolCall($this->server('other-server'));
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(2);
        $this->assertSame(['demo-server', 'other-server'], [JsonAssert::at($this->body(0), 'server_name'), JsonAssert::at($this->body(1), 'server_name')]);

        $this->toolCall($this->server(project: 'proj_bbbbbbbbbbbbbbbbbbbbbbbbbbb'));
        $this->queue->endRequest(1.0);
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
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(0);
        $this->assertSame([], $source->asked);
        $this->assertSame([], $this->logger->messages());
    }

    public function testV10AnApiThatNeverAnswersKeepsNoRequestWaiting(): void
    {
        $this->oldMemory();
        $this->channel->complete = false;

        $this->toolCall($this->server());
        $this->assertSame(0, $this->transport->waits, 'the check waited for nothing');
        $this->assertFalse($this->queue->endRequest(1.0));
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the request-end window ended at its 1 s');
        $waits = $this->transport->waits;

        $this->queue->pump(); // the next request
        $this->assertTrue($this->queue->flush(), 'a flush of the customer does not wait for the heartbeat');
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(1);
        $this->assertSame($waits, $this->transport->waits, 'no later pass waited for it');
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON);
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{array<string, array<string, array{list<mixed>, ?string}>|\Throwable>, (\Closure(string): string)|null, string}> */
    public static function failures(): iterable
    {
        $tools = FakeServerInfo::demo();
        $tools[ServerInfoSource::TOOLS] = new \RuntimeException('the handler failed');
        yield 'a list handler fails' => [$tools, null, 'Skipped the server info heartbeat: the tool list failed - RuntimeException'];
        $error = FakeServerInfo::demo();
        $error[ServerInfoSource::TOOLS] = new \TypeError('not a handler');
        yield 'a list handler fails with an Error' => [$error, null, 'Skipped the server info heartbeat: the tool list failed - TypeError'];
        yield 'the redaction function fails' => [FakeServerInfo::demo(), static fn(string $text): string => throw new \DomainException('redactor down'), 'Skipped the server info heartbeat: redactSensitiveInformation failed - DomainException'];
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
        $this->queue->endRequest(1.0);
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(0);
        $this->assertSame([$line], $this->logger->messages());
    }

    public function testV10After500NoSecondPostFollows(): void
    {
        $this->oldMemory();
        $this->channel = new RecordingHttpChannel(default: new HttpResponse(500, 'Internal Server Error', ''));
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0); // a worker whose bridge ends each request in a window
        $server = $this->server();

        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->queue->endRequest(1.0);

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

        error_clear_last();
        $this->expectOutputString('');

        try {
            $this->toolCall($this->server(options: new Options(redactSensitiveInformation: $redactor)), source: new FakeServerInfo($lists));
            $this->queue->endRequest(1.0);
            $this->assertNull(error_get_last(), 'PHP\'s own error handling, which can print the message, saw nothing');
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

        Heartbeat::onToolList($this->server(), static fn(): string => 'demo-server', static fn(): ServerInfoSource => throw new \LogicException('the request has no session'));
        $this->queue->endRequest(1.0);

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
        $this->queue->endRequest(1.0);

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
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: redactSensitiveInformation must return a string, got int'], $this->logger->messages());
    }

    public function testV13After413TheQueueSendsTheSmallerBodyAndStopsAfterASecond413(): void
    {
        $this->oldMemory();
        $this->channel = new RecordingHttpChannel([new HttpResponse(413, 'Payload Too Large', ''), new HttpResponse(202, 'Accepted', '')]);
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0); // a worker whose bridge ends each request in a window

        $this->toolCall($this->server());
        $this->assertTrue($this->queue->endRequest(1.0));

        $this->assertHeartbeats(2);
        $this->assertObjectHasProperty('inputSchema', JsonAssert::object(JsonAssert::at($this->body(0), 'features', 'tools', 0)));
        foreach (JsonAssert::list(JsonAssert::at($this->body(1), 'features', 'tools')) as $index => $tool) {
            $this->assertObjectNotHasProperty('inputSchema', JsonAssert::object($tool));
            $this->assertObjectNotHasProperty('outputSchema', JsonAssert::object($tool));
            $full = JsonAssert::at($this->body(0), 'features', 'tools', $index);
            $this->assertSame([JsonAssert::at($full, 'name'), JsonAssert::optional($full, 'title'), JsonAssert::at($full, 'description')], [JsonAssert::at($tool, 'name'), JsonAssert::optional($tool, 'title'), JsonAssert::at($tool, 'description')]);
        }

        $this->channel = new RecordingHttpChannel(default: new HttpResponse(413, 'Payload Too Large', ''));
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0); // a worker whose bridge ends each request in a window
        $this->toolCall($this->server('other-server'));
        $this->assertTrue($this->queue->endRequest(1.0));
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(2, '413 two times: two posts, no third');
    }

    public function testV14ACustomApiBaseUrlGetsThePost(): void
    {
        $this->oldMemory();
        $this->queue->configure('https://custom.example.test/base/');

        $this->toolCall($this->server());
        $this->queue->endRequest(1.0);

        $this->assertSame(['https://custom.example.test/base/v1/public/server-info/'], $this->channel->urls());
    }

    public function testV17ANewMemorySendsForAToolListAndForAFeedbackNoteOnly(): void
    {
        $server = $this->server();

        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(0, 'none after the tool call');
        $this->assertFalse($this->queue->hasWork());

        $this->toolList($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1, 'one after the list request');

        Heartbeat::forget(); // a second new memory
        $this->toolCall($server, 'get_more_tools');
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(2, 'one after the get_more_tools call');
    }

    public function testV17WithReportMissingOffGetMoreToolsIsAToolCallLikeAnyOther(): void
    {
        $this->toolCall($this->server(options: new Options(enableReportMissing: false)), 'get_more_tools');
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(0);
    }

    public function testV18WithNoMemoryBetweenRequestsEachToolListSendsAndNoToolCallDoes(): void
    {
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $request) {
            Heartbeat::forget(); // the memory of the request before is gone
            $server = $this->server(); // and each request tracks a new server
            $request === 'call' ? $this->toolCall($server) : $this->toolList($server);
            $this->queue->endRequest(1.0);
        }

        $this->assertHeartbeats(3);
    }

    public function testV19AToolCallSendsOnlyInAMemoryOfSixtySeconds(): void
    {
        $server = $this->server();

        $this->toolCall($server); // an age of 0 s
        $this->queue->endRequest(1.0);
        $this->now += 59.0;
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(0, 'none at 0 s and at 59 s');

        $this->now += 1.0;
        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1, 'one at 60 s');
    }

    public function testAnErrorOfTheBuildThatIsNoListFailureSendsNothingAndIsLogged(): void
    {
        $this->oldMemory();
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::PROMPTS] = ['' => [[(object) ['name' => NAN]], null]]; // JSON cannot carry it

        $this->toolCall($this->server(), source: new FakeServerInfo($lists));
        $this->queue->endRequest(1.0);

        $this->queue->shutdown();

        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: JsonException'], $this->logger->messages(), 'and nothing of it is on its way at the shutdown');
    }

    /** A stdio server and a worker loop without a framework bridge have no window after the response. */
    public function testAProcessWithoutARequestEndWindowStartsNoBuildInAnyPumpAndNotesNothing(): void
    {
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: false);
        $source = new FakeServerInfo();
        $made = 0;
        $server = $this->server();
        for ($request = 0; $request < 3; ++$request) {
            $this->queue->pump(); // the first line of each hook
            Heartbeat::onToolList($server, static fn(): string => 'demo-server', static function () use (&$made, $source): FakeServerInfo {
                ++$made;

                return $source;
            });
            $this->toolCall($server, 'get_more_tools', $source);
            $this->now += Guard::INTERVAL_SECONDS;
        }
        $this->queue->pump();
        $this->queue->flush();
        $this->queue->shutdown();

        $this->assertSame([0, []], [$made, $source->asked]);
        $this->assertHeartbeats(0);
        $this->assertSame([], $this->logger->messages(), 'decided once: no line for each request');

        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: false);
        $this->queue->endRequest(1.0);
        $this->toolList($this->server());
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1, 'no attempt was noted in the process without a window');
    }

    public function testAWorkerWhoseWindowEndsWithAnEventInFlightDropsTheHeartbeatAndTheNextRequestReadsNoList(): void
    {
        $this->oldMemory();
        $this->transport->complete = false; // the event is not delivered inside the window
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: self::PROJECT, eventType: 'mcp:tools/call')));

        $this->toolCall($this->server(), source: $source = new FakeServerInfo());
        $this->assertFalse($this->queue->endRequest(1.0));
        $this->assertSame(['Skipped the server info heartbeat: the request-end window ended before the events were delivered'], $this->logger->messages());

        $this->transport->complete = true;
        $this->queue->pump(); // the next request
        $this->toolCall($this->server());
        $this->queue->endRequest(1.0);

        $this->assertSame([], $source->asked);
        $this->assertHeartbeats(0, 'and the attempt holds the key for its hour');
    }

    public function testAFlushOfTheCustomerNeverRunsTheBuild(): void
    {
        $this->oldMemory();

        $this->toolCall($this->server(), source: $source = new FakeServerInfo());
        $this->queue->pump();
        $this->assertTrue($this->queue->flush());
        $this->assertTrue($this->queue->flush(1.0));

        $this->assertSame([], $source->asked);
        $this->assertHeartbeats(0);
        $this->assertEqualsWithDelta(0.0, $this->transport->now(), PHP_FLOAT_EPSILON);
    }

    /** The name of the guard's key comes from the hook, and the names of the body from the source of the request. */
    public function testNeitherTheCheckNorTheRunReadsTheServerInfoOfTheTrackedServer(): void
    {
        $this->oldMemory();
        $reads = new \ArrayObject();
        $server = new TrackedServer(self::PROJECT, new Options(), static function () use ($reads): array {
            $reads->append('read');

            return ['another-name', '9.9.9'];
        }, new Publisher($this->queue));

        Heartbeat::onToolList($server, static fn(): string => 'demo-server', static fn(): FakeServerInfo => new FakeServerInfo());
        Heartbeat::onToolCall($server, 'alpha', static fn(): string => 'demo-server', static fn(): FakeServerInfo => new FakeServerInfo());
        $this->queue->endRequest(1.0);

        $this->assertCount(0, $reads);
        $this->assertHeartbeats(1);
        $this->assertSame(['demo-server', '1.2.3'], [JsonAssert::at($this->body(0), 'server_name'), JsonAssert::at($this->body(0), 'server_version')]);
    }

    public function testNoLogLineAndNoDiagnosticsLineHoldsAMessageOrACursorOfTheCustomersCode(): void
    {
        $this->oldMemory();
        $diagnostics = [];
        Log::setDiagnosticsSink(static function (string $line) use (&$diagnostics): void {
            $diagnostics[] = $line;
        });
        $failing = FakeServerInfo::demo();
        $failing[ServerInfoSource::TOOLS] = new \RuntimeException('the secret of the tool handler');
        $partly = FakeServerInfo::demo();
        $partly[ServerInfoSource::PROMPTS] = new \RuntimeException('the secret of the prompt handler');
        $partly[ServerInfoSource::RESOURCES] = static fn(): array => [[], 'a secret cursor'];
        $redactor = static fn(string $text): string => throw new \DomainException('the secret that the redactor hides: ' . $text);
        $nan = FakeServerInfo::demo();
        $nan[ServerInfoSource::PROMPTS] = ['' => [[(object) ['name' => NAN, 'description' => 'a secret description']], null]];

        $this->toolCall($this->server('one'), source: new FakeServerInfo($failing));
        $this->toolCall($this->server('two'), source: new FakeServerInfo($partly));
        $this->toolCall($this->server('three', new Options(redactSensitiveInformation: $redactor)), source: new FakeServerInfo(instructions: 'secret instructions'));
        $this->toolCall($this->server('four'), source: new FakeServerInfo($nan));
        Heartbeat::onToolList($this->server('five'), static fn(): string => throw new \LogicException('a secret name'), static fn(): FakeServerInfo => new FakeServerInfo());
        $this->queue->endRequest(1.0);

        $lines = $this->logger->messages();
        $this->assertCount(5, $lines);
        $this->assertContains('Skipped the server info heartbeat: the tool list failed - RuntimeException', $lines);
        $this->assertContains('Skipped the server info heartbeat: redactSensitiveInformation failed - DomainException', $lines);
        $this->assertContains('Skipped the server info heartbeat: JsonException', $lines);
        $this->assertContains('Skipped the server info heartbeat: the check failed - LogicException', $lines);
        $sent = array_values(array_filter($lines, static fn(string $line): bool => str_starts_with($line, 'Sent server info heartbeat')));
        $this->assertStringEndsWith(' | without resources (a cursor came back after page 2), prompts (RuntimeException)', implode("\n", $sent));
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString('secret', implode("\n", $lines));
        $this->assertCount(5, $diagnostics);
        $this->assertStringNotContainsString('secret', implode("\n", $diagnostics));
    }

    public function testWhatAListHandlerOrTheRedactorPrintsIsDiscarded(): void
    {
        $this->oldMemory();
        $this->expectOutputString('');
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::PROMPTS] = static function (): array {
            echo 'printed by a list handler';
            ob_start(); // a buffer that the handler leaves open
            print 'and into its own buffer';

            return [[], null];
        };
        $redactor = static function (string $text): string {
            echo 'printed by the redactor';

            return $text;
        };
        $level = ob_get_level();

        $this->toolCall($this->server(options: new Options(redactSensitiveInformation: $redactor)), source: new FakeServerInfo($lists));
        $this->queue->endRequest(1.0);

        $this->assertSame($level, ob_get_level(), 'every buffer of the run is closed');
        $this->assertHeartbeats(1);
    }

    /** @return iterable<string, array{string}> */
    public static function endsOfABuild(): iterable
    {
        yield 'a body, and the POST is on its way' => ['sent'];
        yield 'a skip: the tool list failed' => ['skipped'];
        yield 'a failure: JSON cannot carry the body' => ['failed'];
        yield 'dropped: the window ended before the events were delivered' => ['dropped'];
    }

    #[DataProvider('endsOfABuild')]
    public function testWhenTheBuildHasEndedNothingRefersToTheSourceOfTheRequest(string $end): void
    {
        $this->oldMemory();
        $this->channel->complete = false; // the API does not answer
        $lists = FakeServerInfo::demo();
        if ($end === 'skipped') {
            $lists[ServerInfoSource::TOOLS] = new \RuntimeException('down');
        } elseif ($end === 'failed') {
            $lists[ServerInfoSource::PROMPTS] = ['' => [[(object) ['name' => NAN]], null]];
        } elseif ($end === 'dropped') {
            $this->transport->complete = false;
            $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: self::PROJECT, eventType: 'mcp:tools/call')));
        }
        $source = new FakeServerInfo($lists);
        $request = \WeakReference::create($source); // stands for the session or the context of the request

        $this->toolCall($this->server(), source: $source);
        $this->assertInstanceOf(FakeServerInfo::class, $request->get(), 'until the window, the pending heartbeat keeps it');
        $this->queue->endRequest(1.0);
        unset($source, $lists);

        $this->assertHeartbeats($end === 'sent' ? 1 : 0);
        $this->assertSame($end === 'sent' ? 1 : 0, $this->channel->pending(), 'the POST has no answer yet');
        $this->assertNull($request->get());
    }

    public function testAnErrorOutsideTheListsAndTheRedactionSendsNothingAndIsLoggedByItsClass(): void
    {
        $this->oldMemory();

        $this->toolCall($this->server(), source: new FakeServerInfo(title: new \LogicException('the secret title cannot be read')));
        $this->queue->endRequest(1.0);

        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: LogicException'], $this->logger->messages());
    }

    public function testAPendingHeartbeatRunsOnce(): void
    {
        $source = new FakeServerInfo();
        $pending = new PendingHeartbeat($this->server(), $source, fn(): float => $this->now);

        $this->assertInstanceOf(\AgentCat\Internal\ServerInfo\SentHeartbeat::class, $pending->run(1.0));
        $asked = count($source->asked);
        $this->assertNull($pending->run(1.0), 'it let go of the lists at its first run');

        $this->assertCount($asked, $source->asked);
        $this->assertSame('', implode("\n", $this->logger->messages()), 'the second run does nothing and says nothing');
        $this->queue->endRequest(1.0);
        $this->assertHeartbeats(1);
    }

    public function testAnAnonymousClassOfTheCustomersCodeIsNamedByAFixedWord(): void
    {
        $this->oldMemory();
        $prompts = FakeServerInfo::demo();
        $prompts[ServerInfoSource::PROMPTS] = new class ('boom') extends \RuntimeException {};
        $tools = FakeServerInfo::demo();
        $tools[ServerInfoSource::TOOLS] = new class ('boom') extends \RuntimeException {};
        $redactor = static fn(string $text): string => throw new class ('boom') extends \DomainException {};

        $this->toolCall($this->server('one'), source: new FakeServerInfo($prompts));
        $this->toolCall($this->server('two'), source: new FakeServerInfo($tools));
        $this->toolCall($this->server('three', new Options(redactSensitiveInformation: $redactor)));
        Heartbeat::onToolList($this->server('four'), static fn(): string => throw new class ('boom') extends \LogicException {}, static fn(): FakeServerInfo => new FakeServerInfo());
        $this->queue->endRequest(1.0);

        $lines = implode("\n", $this->logger->messages());
        $this->assertStringContainsString('| without prompts (an anonymous class)', $lines);
        $this->assertStringContainsString('Skipped the server info heartbeat: the tool list failed - an anonymous class', $lines);
        $this->assertStringContainsString('Skipped the server info heartbeat: redactSensitiveInformation failed - an anonymous class', $lines);
        $this->assertStringContainsString('Skipped the server info heartbeat: the check failed - an anonymous class', $lines);
        $this->assertStringNotContainsString('@anonymous', $lines);
        $this->assertStringNotContainsString("\0", $lines);
        $this->assertStringNotContainsString('HeartbeatTest.php', $lines, 'no path of the file that declares the class');
    }

    public function testAServerWhoseNameIsUnknownSendsNothing(): void
    {
        $this->oldMemory();
        $server = new TrackedServer(self::PROJECT, new Options(), static fn(): array => [null, null], new Publisher($this->queue));
        $empty = new TrackedServer(self::PROJECT, new Options(), static fn(): array => ['', '1'], new Publisher($this->queue));

        $this->toolCall($server);
        $this->queue->endRequest(1.0);
        $this->now += Guard::INTERVAL_SECONDS;
        $this->toolCall($empty);
        $this->queue->endRequest(1.0);

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
        $this->queue->endRequest(60.0);

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
        $this->queue->endRequest(60.0);

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
        $this->queue->endRequest(60.0);

        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: reading and redacting the lists took more than 30 s'], $this->logger->messages());
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
        $this->assertTrue($this->queue->endRequest(1.0));

        $this->assertSame(['tools@'], $source->asked, 'the page that ran could not be stopped; no other page was asked for');
        $this->assertHeartbeats(0);
        $this->assertSame(['Skipped the server info heartbeat: the delivery time ended before the lists were read'], $this->logger->messages());
    }

    public function testAPageThatUsesExactlyTheWindowLeavesNoTimeForTheNextOne(): void
    {
        $this->oldMemory();
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::TOOLS] = function (): array {
            $this->now += 1.0;

            return [[FakeServerInfo::tool('alpha')], null];
        };

        $this->toolCall($this->server(), source: $source = new FakeServerInfo($lists));
        $this->queue->endRequest(1.0);

        $this->assertSame(['tools@'], $source->asked);
        $this->assertHeartbeats(0);
    }

    public function testAPendingHeartbeatDoesNotMakeAWorkersRequestEndWaitLonger(): void
    {
        $this->oldMemory();
        $this->channel->complete = false; // the API never answers the heartbeat
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: self::PROJECT, eventType: 'mcp:tools/call')));

        $this->toolCall($this->server());
        $this->assertFalse($this->queue->endRequest(1.0));

        $this->assertCount(1, $this->transport->deliveries, 'the event went first');
        $this->assertHeartbeats(1);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the wait ended with the 1 s window of the event send');
    }

    public function testAPendingHeartbeatDoesNotMoveTheShutdownDeadlineOfAPerRequestSapi(): void
    {
        $this->oldMemory();
        $this->channel->complete = false;
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: true);
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: self::PROJECT, eventType: 'mcp:tools/call')));

        $this->toolCall($this->server());
        $this->queue->pump();
        $this->queue->flush();
        $this->queue->endRequest(0.0);
        $this->assertHeartbeats(0, 'nothing before the response is out');
        $this->queue->responseSent();
        $this->queue->finalShutdown();

        $this->assertHeartbeats(1);
        $this->assertSame(EventQueue::SHUTDOWN_TIMEOUT_SECONDS, $this->transport->now());
        $this->assertSame(['Skipped the server info heartbeat: the delivery time ended before the AgentCat API answered'], $this->logger->messages(), 'the request that the deadline cut off gets its line');
    }

    public function testWhereTheClientDoesNotGetItsResponseFirstNoHeartbeatIsSent(): void
    {
        $this->oldMemory();
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: true);

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
        $this->queue->endRequest(1.0);

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
        Heartbeat::onToolCall($server, $tool, static fn(): ?string => $server->serverInfo()[0], static fn(): FakeServerInfo => self::named($server, $source));
    }

    private function toolList(TrackedServer $server, ?FakeServerInfo $source = null): void
    {
        Heartbeat::onToolList($server, static fn(): ?string => $server->serverInfo()[0], static fn(): FakeServerInfo => self::named($server, $source));
    }

    /** The source of a request of this server: it names the server as the server does. */
    private static function named(TrackedServer $server, ?FakeServerInfo $source): FakeServerInfo
    {
        $source ??= new FakeServerInfo();
        [$source->name, $source->version] = $server->serverInfo();

        return $source;
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
