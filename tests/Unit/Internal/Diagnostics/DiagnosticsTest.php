<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Diagnostics;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Diagnostics\DiagnosticsAttributes;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\LogLine;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    private const string IGNORED = 'WARN: AgentCat diagnostics were set up by an earlier track(); disableDiagnostics: true in this call is ignored';

    private const int WALL_MILLIS = 1_792_163_096_821;

    /** Read by diagnostics (D50), and the unprefixed names an application can have for itself. */
    private const array VARIABLES = [
        'AGENTCAT_DISABLE_DIAGNOSTICS', 'AGENTCAT_DIAGNOSTICS_ENDPOINT', 'AGENTCAT_DIAGNOSTICS_TOKEN',
        'DISABLE_DIAGNOSTICS', 'DIAGNOSTICS_ENDPOINT', 'DIAGNOSTICS_TOKEN', 'APP_ENV',
    ];

    private Environment $environment;

    private MemoryLogger $logger;

    private RecordingHttpChannel $http;

    private EventQueue $queue;

    private float $now = 100.0;

    private int $armed = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear(...self::VARIABLES);
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=' . MockCollector::closedPortUrl()); // fail closed (ruling P8)
        putenv('DIAGNOSTICS_ENDPOINT=' . MockCollector::closedPortUrl()); // an application's own variable: never read (D50)
        Diagnostics::reset();
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->http = new RecordingHttpChannel();
        $this->queue = $this->queueOn($this->http);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Diagnostics::reset();
        BridgeConfig::reset();
        Log::reset();
        $this->environment->restore();
    }

    #[Group('ac-06-54')]
    public function testAWarningLineIsPostedToTheDefaultCollector(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT'); // the default URL, with a recording channel (ruling F8)
        $this->init('proj_ABC');
        Log::write('Warning: something');

        $this->queue->shutdown();

        $this->assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        $this->assertSame('https://otel.agentcat.com/v1/logs', $request->url);
        $this->assertSame(['Content-Type' => 'application/json', 'Authorization' => 'Bearer dgk_sdk_diag_3f9a2c7e1b8d4065af2e9c1d7b6a4f80'], $request->headers);
        $body = Json::decode($request->body);
        $this->assertSame(Constants::DIAGNOSTICS_SCOPE_NAME, JsonAssert::at($body, 'resourceLogs', 0, 'scopeLogs', 0, 'scope', 'name'));
        $this->assertSame(SdkVersion::get(), JsonAssert::at($body, 'resourceLogs', 0, 'scopeLogs', 0, 'scope', 'version'));
        $this->assertSame(Json::encodeJs(DiagnosticsAttributes::resource('proj_ABC')), Json::encodeJs(JsonAssert::at($body, 'resourceLogs', 0, 'resource', 'attributes')));
        $record = JsonAssert::at($body, 'resourceLogs', 0, 'scopeLogs', 0, 'logRecords', 0);
        $this->assertSame(13, JsonAssert::at($record, 'severityNumber'));
        $this->assertSame('WARN', JsonAssert::at($record, 'severityText'));
        $this->assertMatchesRegularExpression('/\A\[\d{4}-\d\d\-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] \[sdk=\S+ php=\S+ mcp=\S+(?: laravel-mcp=\S+)?\] Warning: something\z/', $this->text($record));
        $this->assertSame('1792163096821000000', JsonAssert::at($record, 'timeUnixNano'));
        $this->assertSame(Json::encodeJs(DiagnosticsAttributes::record()), Json::encodeJs(JsonAssert::at($record, 'attributes')));
        $this->assertSame(1, $this->armed, 'the first line registers the shutdown delivery');
    }

    public function testOnlyTheFirstLineArmsTheQueue(): void
    {
        $this->init('proj_ABC');

        Log::write('one');
        Log::write('two');
        Log::write('three');

        $this->assertSame(1, $this->armed);
    }

    /** The arm() failure line comes back through capture(): it is buffered, and arm() is not called again. */
    public function testAFailingDeliveryRegistrationIsBufferedOnce(): void
    {
        $calls = 0;
        $this->queue = new EventQueue(transport: new RecordingTransport(), onEnqueue: static function () use (&$calls): never {
            ++$calls;

            throw new \RuntimeException('no shutdown functions');
        }, channel: $this->http);
        $this->init('proj_ABC');

        Log::write('one');
        $this->queue->shutdown();

        $failure = 'Failed to register AgentCat event delivery as a background task: RuntimeException: no shutdown functions';
        $this->assertSame(1, $calls);
        $this->assertSame([$failure, 'one'], $this->logger->messages(), 'the sink runs first, so the nested line reaches the logger first');
        $records = $this->records(0);
        $this->assertCount(2, $records);
        $this->assertStringEndsWith('] one', $this->text($records[0]));
        $this->assertStringEndsWith('] ' . $failure, $this->text($records[1]));
    }

    #[Group('ac-06-58')]
    public function testTheBufferKeepsTheNewest1000Records(): void
    {
        $this->init('proj_ABC');
        for ($i = 0; $i <= Diagnostics::MAX_BUFFER; ++$i) {
            Log::write('line ' . $i);
        }

        $this->queue->shutdown();

        $records = $this->records(0);
        $this->assertCount(1000, $records);
        $this->assertStringEndsWith('] line 1', $this->text($records[0]));
        $this->assertStringEndsWith('] line 1000', $this->text($records[999] ?? null));
    }

    /** A6: the buffer holds at most 1 MiB of record bodies; the oldest records go first, silently. */
    #[Group('ac-06-58')]
    public function testTheBufferKeepsItsRecordBodiesWithinOneMebibyte(): void
    {
        $this->init('proj_ABC');
        $text = str_repeat('x', 100_000);
        for ($i = 0; $i < 12; ++$i) {
            Log::write($i . ' ' . $text);
        }

        $this->queue->shutdown();

        $records = $this->records(0);
        $this->assertCount(10, $records, 'ten bodies of about 100 KB fit in 1 MiB, eleven do not');
        $this->assertStringEndsWith('] 2 ' . $text, $this->text($records[0]));
        $this->assertStringEndsWith('] 11 ' . $text, $this->text($records[9]));
        $bytes = array_sum(array_map(fn(mixed $record): int => strlen($this->text($record)), $records));
        $this->assertLessThanOrEqual(Diagnostics::MAX_BUFFER_BYTES, $bytes);
        $this->assertCount(12, $this->logger->messages(), 'no line about the dropped records');
    }

    /** A6: records that fill the budget exactly all stay, one byte more drops the oldest; each batch starts a new budget. */
    public function testTheByteBudgetHoldsExactlyOneMebibyte(): void
    {
        $this->init('proj_ABC');
        $half = intdiv(Diagnostics::MAX_BUFFER_BYTES, 2) - strlen(LogLine::format(''));
        Log::write(str_repeat('a', $half));
        Log::write(str_repeat('b', $half));
        $this->now += 2.0;
        $this->queue->pump();
        Log::write(str_repeat('c', $half));
        Log::write(str_repeat('d', $half + 1));

        $this->queue->shutdown();

        $this->assertCount(2, $this->records(0), 'exactly 1 MiB of bodies fits');
        $second = $this->records(1);
        $this->assertCount(1, $second, 'one byte more does not');
        $this->assertStringContainsString('] dd', $this->text($second[0]));
    }

    /** A6: a line larger than the whole budget is kept alone, until the next line drops it; each batch starts a new budget. */
    public function testALineOverTheBudgetIsKeptAlone(): void
    {
        $this->init('proj_ABC');
        Log::write('small');
        Log::write('huge ' . str_repeat('x', Diagnostics::MAX_BUFFER_BYTES));
        $this->now += 2.0;
        $this->queue->pump();
        Log::write('first after the batch');
        Log::write('huge again ' . str_repeat('x', Diagnostics::MAX_BUFFER_BYTES));
        Log::write('last');

        $this->queue->shutdown();

        $this->assertSame(2, $this->posts());
        $first = $this->records(0);
        $this->assertCount(1, $first);
        $this->assertStringContainsString('] huge xxx', $this->text($first[0]));
        $second = $this->records(1);
        $this->assertCount(1, $second, 'each line drops the older records that do not fit beside it');
        $this->assertStringEndsWith('] last', $this->text($second[0]));
    }

    /** Q3: no timer — a queue pass after the 2 s window posts; a pass before it does not. */
    public function testTheTwoSecondWindowIsCheckedOnQueuePasses(): void
    {
        $this->init('proj_ABC');
        Log::write('first');
        $this->now += 1.5;
        Log::write('more');
        $this->queue->pump();
        $this->assertSame(0, $this->posts());

        $this->now += 0.5; // exactly 2.0 s after the first line (binary-exact steps)
        $this->queue->pump();
        $this->assertSame(1, $this->posts());
        $this->assertCount(2, $this->records(0), 'the window starts with the first buffered line');

        Log::write('second');
        $this->queue->pump();
        $this->assertSame(1, $this->posts(), 'a new window starts with the next line');

        $this->now += 2.0;
        $this->assertTrue($this->queue->flush(1.0), 'flush() passes check the window too');
        $this->assertSame(2, $this->posts());
    }

    /** @return iterable<string, array{string}> */
    public static function longRunningSapis(): iterable
    {
        yield 'command line' => ['cli'];
        yield 'phpdbg' => ['phpdbg'];
    }

    /** D47: a process that outlives its requests (a stdio server, a worker) keeps the TypeScript 2 s window. */
    #[DataProvider('longRunningSapis')]
    public function testALongRunningProcessPostsOnceItsOldestLineIsTwoSecondsOld(string $sapi): void
    {
        $this->init('proj_ABC', sapi: $sapi);
        Log::write('first');
        $this->now += 2.0;

        $this->queue->pump();

        $this->assertSame(1, $this->posts());
    }

    /** @return iterable<string, array{string}> */
    public static function perRequestSapis(): iterable
    {
        yield 'PHP-FPM' => ['fpm-fcgi'];
        yield 'mod_php' => ['apache2handler'];
        yield 'php -S' => ['cli-server'];
        yield 'FrankenPHP classic mode' => ['frankenphp'];
    }

    /**
     * D47: a per-request runtime posts its lines once, when the queue shuts down after the response, also when the
     * request or its drain (a slow hook, a slow AgentCat API) lasts longer than the 2 s window of long-running processes.
     */
    #[Group('ac-06-62')]
    #[DataProvider('perRequestSapis')]
    public function testAPerRequestRuntimePostsItsLinesOnceAtShutdown(string $sapi): void
    {
        $this->init('proj_ABC', sapi: $sapi);
        Log::write('setup');
        $this->now += 3.0;
        $this->queue->pump();
        $this->assertTrue($this->queue->flush(1.0));
        $this->assertSame(0, $this->posts(), 'no queue pass posts before the shutdown');
        Log::write('sent');

        $this->queue->shutdown();

        $this->assertSame(1, $this->posts());
        $records = $this->records(0);
        $this->assertCount(2, $records, 'every line of the request in one post');
        $this->assertStringEndsWith('] setup', $this->text($records[0]));
        $this->assertStringEndsWith('] sent', $this->text($records[1]));
    }

    /**
     * I1: a collector that never answers (black-holed otel.agentcat.com). The flush pass posts the batch, as its window
     * is over, and returns true at once: AgentCat's own request is neither counted nor waited for.
     */
    public function testFlushNeitherCountsNorWaitsForTheBatchItPosts(): void
    {
        $this->http = new RecordingHttpChannel(complete: false);
        $this->queue = $this->queueOn($this->http);
        $this->init('proj_ABC');
        Log::write('a line');
        $this->now += 2.0;

        $this->assertTrue($this->queue->flush(0.3));

        $this->assertSame(1, $this->posts(), 'the flush pass posted the batch');
        $this->assertTrue(($this->http->requests[0] ?? null)?->background);
        $this->assertSame([], $this->http->waits, 'and did not wait for it');
        $this->assertSame(1, $this->http->pending());
    }

    /** D47: `shutdown()` still waits for the batch inside its budget. */
    public function testShutdownWaitsForTheBatchItPosts(): void
    {
        $this->http = new RecordingHttpChannel(complete: false, onWait: function (): void {
            $this->http->complete = true; // the collector answers during the first wait
        });
        $this->queue = $this->queueOn($this->http);
        $this->init('proj_ABC');
        Log::write('a line');

        $this->queue->shutdown();

        $this->assertSame(1, $this->posts());
        $this->assertCount(1, $this->http->waits);
        $this->assertSame(0, $this->http->pending(), 'the batch was sent');
    }

    #[Group('ac-06-59')]
    public function testAnUnreachableCollectorDiscardsTheRecordsSilently(): void
    {
        $this->http = new RecordingHttpChannel(default: HttpResponse::failed('cURL error 7: refused'));
        $this->queue = $this->queueOn($this->http);
        $this->init('proj_ABC');
        Log::write('one');

        $this->queue->shutdown();

        $this->assertCount(1, $this->http->requests);
        $this->assertFalse($this->queue->hasWork());
        $this->assertSame(['one'], $this->logger->messages(), 'no line about the failure');
    }

    public function testAChannelThatThrowsLosesTheRecordsSilently(): void
    {
        $channel = new class implements HttpChannel {
            public int $posts = 0;

            #[\Override]
            public function post(HttpRequest $request, \Closure $onDone): void
            {
                ++$this->posts;

                throw new \RuntimeException('post');
            }

            #[\Override]
            public function pump(): void {}

            #[\Override]
            public function pending(bool $includeBackground = true): int
            {
                return 0;
            }

            #[\Override]
            public function waitForActivity(float $seconds): void {}
        };
        $this->queue = $this->queueOn($channel);
        $this->init('proj_ABC');
        Log::write('one');

        $this->queue->shutdown();

        $this->assertSame(1, $channel->posts);
        $this->assertFalse($this->queue->hasWork());
        $this->assertSame(['one'], $this->logger->messages());
    }

    /** Review Focus 5 (ruling P6): a real file at the `/v1/logs` path, so a fetched file would come back as the body. */
    public function testAFileEndpointIsNeverRead(): void
    {
        $dir = getenv('HOME') . '/diagnostics-' . bin2hex(random_bytes(6));
        mkdir($dir . '/v1', 0o700, true);
        file_put_contents($dir . '/v1/logs', 'root:x:0:0:root:/root:/bin/sh');
        $channel = new class (new CurlHttpChannel()) implements HttpChannel {
            /** @var list<HttpRequest> */
            public array $requests = [];

            /** @var list<HttpResponse> */
            public array $responses = [];

            public function __construct(private readonly HttpChannel $inner) {}

            #[\Override]
            public function post(HttpRequest $request, \Closure $onDone): void
            {
                $this->requests[] = $request;
                $this->inner->post($request, function (HttpResponse $response) use ($onDone): void {
                    $this->responses[] = $response;
                    $onDone($response);
                });
            }

            #[\Override]
            public function pump(): void
            {
                $this->inner->pump();
            }

            #[\Override]
            public function pending(bool $includeBackground = true): int
            {
                return $this->inner->pending($includeBackground);
            }

            #[\Override]
            public function waitForActivity(float $seconds): void
            {
                $this->inner->waitForActivity($seconds);
            }
        };

        try {
            putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=file://' . $dir . '/v1/logs');
            $this->queue = $this->queueOn($channel);
            $this->init('proj_ABC');
            Log::write('one');

            $this->queue->shutdown();

            $this->assertSame(['file://' . $dir . '/v1/logs'], array_map(static fn(HttpRequest $request): string => $request->url, $channel->requests));
            $this->assertCount(1, $channel->responses);
            $this->assertMatchesRegularExpression('/\AcURL error 1: /', (string) $channel->responses[0]->error);
            $this->assertSame('', $channel->responses[0]->body, 'the file was not read');
            $this->assertSame(['one'], $this->logger->messages(), 'diagnostics stay silent');
            $this->assertFalse($this->queue->hasWork());
        } finally {
            unlink($dir . '/v1/logs');
            rmdir($dir . '/v1');
            rmdir($dir);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function anyVariable(): iterable
    {
        yield 'unset' => [null];
        yield 'AGENTCAT_DISABLE_DIAGNOSTICS=false' => ['false'];
    }

    #[DataProvider('anyVariable')]
    #[Group('ac-06-48')]
    #[Group('ac-01-61')]
    public function testTheDisableOptionBuffersAndSendsNothing(?string $variable): void
    {
        if ($variable !== null) {
            putenv('AGENTCAT_DISABLE_DIAGNOSTICS=' . $variable);
        }
        $this->init('p', disabled: true);
        Log::write('x');

        $this->assertFalse(Diagnostics::enabled());
        $this->assertFalse($this->queue->hasWork());
        $this->assertSame(0, $this->armed);
        $this->queue->shutdown();
        $this->assertSame([], $this->http->requests);
    }

    #[Group('ac-06-49')]
    #[Group('ac-01-62')]
    public function testATrueVariableTurnsDiagnosticsOff(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=true');
        $this->init('p');
        Log::write('x');

        $this->assertFalse(Diagnostics::enabled());
        $this->assertFalse($this->queue->hasWork());
    }

    /** A10 (D50): the unprefixed `DISABLE_DIAGNOSTICS` can only turn diagnostics off, and its off wins. */
    #[Group('ac-06-49')]
    public function testTheUnprefixedVariableCanOnlyTurnDiagnosticsOff(): void
    {
        putenv('DISABLE_DIAGNOSTICS=true');
        $this->init('p');
        $this->assertFalse(Diagnostics::enabled(), 'true turns them off');

        Diagnostics::reset();
        putenv('DISABLE_DIAGNOSTICS=false');
        $this->init('p', testEnvironment: true);
        $this->assertFalse(Diagnostics::enabled(), 'false never turns them on');

        Diagnostics::reset();
        putenv('DISABLE_DIAGNOSTICS=true');
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->init('p', testEnvironment: true);
        $this->assertFalse(Diagnostics::enabled(), 'off wins over AGENTCAT_DISABLE_DIAGNOSTICS=false');
    }

    /** A bridge's configuration (a cached Laravel configuration) can turn diagnostics off, also over a false variable. */
    public function testAConfiguredOptOutTurnsDiagnosticsOff(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        BridgeConfig::configure([], true);

        $this->init('p', testEnvironment: false);

        $this->assertFalse(Diagnostics::enabled());
        $this->assertSame([], $this->logger->messages());
    }

    /** A10 (D50): an application's own `DIAGNOSTICS_ENDPOINT` and `DIAGNOSTICS_TOKEN` never reach AgentCat. */
    #[Group('ac-06-55')]
    public function testTheApplicationsOwnCollectorVariablesAreIgnored(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT'); // the default URL, with a recording channel (ruling F8)
        putenv('DIAGNOSTICS_ENDPOINT=https://diagnostics.app.example');
        putenv('DIAGNOSTICS_TOKEN=the-applications-own-secret');
        $this->init('proj_ABC');
        Log::write('one');

        $this->queue->shutdown();

        $this->assertSame(['https://otel.agentcat.com/v1/logs'], $this->http->urls());
        $this->assertSame('Bearer ' . Constants::DEFAULT_DIAGNOSTICS_TOKEN, $this->http->requests[0]->headers['Authorization'] ?? null);
    }

    /** A9 (D49): a blocked collector holds the shutdown 0.25 s (connect) or 1 s (answer) at most; exports keep 2 s and 10 s. */
    public function testTheBatchCarriesTheShortDiagnosticsTimeouts(): void
    {
        $this->init('proj_ABC');
        Log::write('one');

        $this->queue->shutdown();

        $request = $this->http->requests[0] ?? null;
        $this->assertInstanceOf(HttpRequest::class, $request);
        $this->assertSame([true, 0.25, 1.0], [$request->background, $request->connectTimeoutSeconds, $request->totalTimeoutSeconds]);
    }

    #[Group('ac-06-53')]
    #[Group('ac-01-11')]
    public function testTheFirstInitDecides(): void
    {
        $this->init('p', disabled: true);
        $this->init('p');
        $this->assertFalse(Diagnostics::enabled(), 'a later enabling call changes nothing');
        $this->assertSame([], $this->logger->messages());

        Diagnostics::reset();
        $this->init('p');
        $this->init('p');
        $this->assertSame([], $this->logger->messages(), 'the same option again logs nothing');
        $this->init('p', disabled: true);
        $this->init('p', disabled: true);
        $this->init('p');
        $this->assertTrue(Diagnostics::enabled(), 'a later disabling call changes nothing');
        $this->assertSame([self::IGNORED], $this->logger->messages(), 'one line, once');
        $this->assertTrue($this->queue->hasWork(), 'the line is a diagnostics line too');
    }

    /** The line says diagnostics "were set up": not when the variable or the test environment kept them off. */
    #[Group('ac-06-53')]
    public function testALaterDisablingCallIsSilentWhenDiagnosticsNeverStarted(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=true');
        $this->init('p');
        $this->init('p', disabled: true);

        Diagnostics::reset();
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS');
        $this->init('p', testEnvironment: true);
        $this->init('p', disabled: true);

        $this->assertFalse(Diagnostics::enabled());
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-06-52')]
    #[Group('ac-01-65')]
    public function testATestEnvironmentTurnsDiagnosticsOffUnlessTheVariableIsFalse(): void
    {
        $this->init('p', testEnvironment: true);
        $this->assertFalse(Diagnostics::enabled());

        Diagnostics::reset();
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->init('p', testEnvironment: true);
        $this->assertTrue(Diagnostics::enabled());
    }

    /** P5: the default test signal is `TestMode::inTestEnvironment()`, and PHPUnit runs this test. */
    #[Group('ac-06-52')]
    public function testByDefaultThePhpUnitRunTurnsDiagnosticsOff(): void
    {
        Diagnostics::init('p', false, $this->queue);

        $this->assertFalse(Diagnostics::enabled());
    }

    #[Group('ac-06-50')]
    public function testTheDefaultClocksStampTheRecordAndKeepTheWindowOpen(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        Diagnostics::init('p', false, $this->queue);
        $before = (int) floor(microtime(true) * 1000);
        Log::write('x');
        $after = (int) floor(microtime(true) * 1000);

        $this->queue->pump();
        $this->assertSame([], $this->http->requests, 'the 2 s window is still open');
        $this->queue->shutdown();

        $this->assertTrue(Diagnostics::enabled());
        $stamp = JsonAssert::at($this->records(0)[0] ?? null, 'timeUnixNano');
        $this->assertIsString($stamp);
        $this->assertMatchesRegularExpression('/\A\d+000000\z/', $stamp);
        $millis = (int) substr($stamp, 0, -6);
        $this->assertGreaterThanOrEqual($before, $millis);
        $this->assertLessThanOrEqual($after, $millis);
    }

    #[Group('ac-06-55')]
    public function testTheCollectorAndTokenAreReadAtEachFlush(): void
    {
        $this->init('proj_ABC');
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=https://c.example/');
        putenv('AGENTCAT_DIAGNOSTICS_TOKEN=t');
        Log::write('one');
        $this->now += 2.0;
        $this->queue->pump();

        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=https://c.example/v1/logs//');
        putenv('AGENTCAT_DIAGNOSTICS_TOKEN=');
        Log::write('two');
        $this->now += 2.0;
        $this->queue->pump();

        $this->assertSame(['https://c.example/v1/logs', 'https://c.example/v1/logs'], $this->http->urls());
        $this->assertSame('Bearer t', $this->http->requests[0]->headers['Authorization'] ?? null);
        $this->assertSame('Bearer ' . Constants::DEFAULT_DIAGNOSTICS_TOKEN, $this->http->requests[1]->headers['Authorization'] ?? null);
    }

    #[Group('ac-06-56')]
    public function testTheResourceNamesTheProjectOrAStableInstallId(): void
    {
        $this->init('proj_ABC');
        Log::write('one');
        $this->queue->shutdown();
        $keys = array_map(static fn(mixed $attribute): mixed => JsonAssert::at($attribute, 'key'), $this->resource(0));
        $this->assertSame('agentcat.project_id', $keys[0] ?? null);
        $this->assertSame('proj_ABC', JsonAssert::at($this->resource(0)[0] ?? null, 'value', 'stringValue'));
        $this->assertNotContains('agentcat.install_id', $keys);

        $first = $this->installIdOfANewProcess();
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $first);
        $this->assertSame($first, $this->installIdOfANewProcess());
    }

    /** Spec conflict 9: while `AgentCat::fake()` diverts the log, the diagnostics buffer gets nothing. */
    public function testADivertedLineIsNotBuffered(): void
    {
        $this->init('proj_ABC');

        Log::divert(new MemoryLogger());
        Log::write('x');
        $this->assertFalse($this->queue->hasWork());

        Log::endDiversion();
        Log::write('y');
        $this->assertTrue($this->queue->hasWork());
    }

    public function testAThrowingClockLeavesTheBufferUnchanged(): void
    {
        $calls = 0;
        Diagnostics::init('proj_ABC', false, $this->queue, static fn(): bool => false, static function () use (&$calls): float {
            if (++$calls > 1) {
                throw new \RuntimeException('clock');
            }

            return 100.0;
        }, static fn(): int => self::WALL_MILLIS);

        Log::write('kept');
        Log::write('lost');
        $this->queue->shutdown(); // its ticks read the clock too

        $this->assertSame(['kept', 'lost'], $this->logger->messages());
        $records = $this->records(0);
        $this->assertCount(1, $records);
        $this->assertStringEndsWith('] kept', $this->text($records[0]));
    }

    /** TS `(BigInt(Date.now()) * BigInt(1_000_000)).toString()`. */
    public function testAWallClockAtZeroStampsZero(): void
    {
        Diagnostics::init('proj_ABC', false, $this->queue, static fn(): bool => false, fn(): float => $this->now, static fn(): int => 0);
        Log::write('epoch');

        $this->queue->shutdown();

        $this->assertSame('0', JsonAssert::at($this->records(0)[0] ?? null, 'timeUnixNano'));
    }

    public function testAThrowingWallClockLeavesTheBufferUnchanged(): void
    {
        Diagnostics::init('proj_ABC', false, $this->queue, static fn(): bool => false, fn(): float => $this->now, static fn(): int => throw new \RuntimeException('wall clock'));

        Log::write('lost');

        $this->assertFalse($this->queue->hasWork());
        $this->assertSame(0, $this->armed);
    }

    public function testAThrowingTestSignalLeavesDiagnosticsOff(): void
    {
        Diagnostics::init('p', false, $this->queue, static fn(): bool => throw new \RuntimeException('signal'));
        Log::write('x');

        $this->assertFalse(Diagnostics::enabled());
        $this->assertFalse($this->queue->hasWork());
    }

    public function testTheQueueHasWorkOnlyWhileLinesAreBuffered(): void
    {
        $this->init('proj_ABC');
        $this->assertFalse($this->queue->hasWork());

        Log::write('one');
        $this->assertTrue($this->queue->hasWork(), 'so the PHP-FPM response is finished before the post (D47)');

        $this->now += 2.0;
        $this->queue->pump();
        $this->assertCount(1, $this->http->requests);
        $this->assertFalse($this->queue->hasWork());
    }

    /** `finish()` runs once per `shutdown()` (signal drain, then the shutdown function): an empty buffer posts nothing. */
    public function testEachShutdownPostsOnlyTheLinesSinceTheLast(): void
    {
        $this->init('proj_ABC');
        Log::write('one');
        $this->queue->shutdown();
        $this->queue->shutdown();
        $this->assertSame(1, $this->posts());

        Log::write('late');
        $this->queue->shutdown();

        $this->assertSame(2, $this->posts());
        $records = $this->records(1);
        $this->assertCount(1, $records);
        $this->assertStringEndsWith('] late', $this->text($records[0]));
    }

    public function testInvalidUtf8StillMakesValidJson(): void
    {
        $this->init('proj_ABC');
        Log::write("bad \xFF byte");

        $this->queue->shutdown();

        $this->assertStringEndsWith("] bad \u{FFFD} byte", $this->text($this->records(0)[0] ?? null));
    }

    public function testResetForgetsTheDecisionAndRemovesTheSink(): void
    {
        $this->init('proj_ABC');
        Diagnostics::reset();

        Log::write('x');

        $this->assertFalse(Diagnostics::enabled());
        $this->assertFalse($this->queue->hasWork());
    }

    private function init(string $projectId, bool $disabled = false, bool $testEnvironment = false, ?string $sapi = null): void
    {
        Diagnostics::init($projectId, $disabled, $this->queue, static fn(): bool => $testEnvironment, fn(): float => $this->now, static fn(): int => self::WALL_MILLIS, $sapi);
    }

    private function queueOn(HttpChannel $channel): EventQueue
    {
        return new EventQueue(transport: new RecordingTransport(), onEnqueue: function (): void {
            ++$this->armed;
        }, channel: $channel);
    }

    /** The install ID a fresh `init('')` sends. */
    private function installIdOfANewProcess(): string
    {
        Diagnostics::reset();
        $this->http = new RecordingHttpChannel();
        $this->queue = $this->queueOn($this->http);
        $this->init('');
        Log::write('one');
        $this->queue->shutdown();
        $first = $this->resource(0)[0] ?? null;
        $this->assertSame('agentcat.install_id', JsonAssert::at($first, 'key'));
        $id = JsonAssert::at($first, 'value', 'stringValue');
        $this->assertIsString($id);

        return $id;
    }

    private function posts(): int
    {
        return count($this->http->requests);
    }

    /** @return list<mixed> the log records of request `$index` */
    private function records(int $index): array
    {
        $this->assertArrayHasKey($index, $this->http->requests);
        $records = JsonAssert::at(Json::decode($this->http->requests[$index]->body), 'resourceLogs', 0, 'scopeLogs', 0, 'logRecords');
        $this->assertIsList($records);

        return $records;
    }

    /** @return list<mixed> the resource attributes of request `$index` */
    private function resource(int $index): array
    {
        $this->assertArrayHasKey($index, $this->http->requests);
        $attributes = JsonAssert::at(Json::decode($this->http->requests[$index]->body), 'resourceLogs', 0, 'resource', 'attributes');
        $this->assertIsList($attributes);

        return $attributes;
    }

    private function text(mixed $record): string
    {
        $text = JsonAssert::at($record, 'body', 'stringValue');
        $this->assertIsString($text);

        return $text;
    }
}
