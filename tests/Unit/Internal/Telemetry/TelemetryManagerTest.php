<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Event;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Exporter;
use AgentCat\Internal\Telemetry\JsError;
use AgentCat\Internal\Telemetry\TelemetryManager;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Samples\MissingSleepProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TelemetryManagerTest extends TestCase
{
    private const string SENTRY = 'https://o1.ingest.sentry.io/api/42/envelope/';

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-06-10')]
    public function testEveryConfigTypeBuildsItsExporterAndEveryExporterSends(): void
    {
        $http = new RecordingHttpChannel();
        $manager = TelemetryManager::fromConfigs([
            'otlp' => new OtlpExporter('http://otlp.test'),
            'dd' => new DatadogExporter('k', 'datadoghq.com', 'svc'),
            'sentry' => new SentryExporter('https://abc@o1.ingest.sentry.io/42'),
            'ph' => new PostHogExporter('phc_x'),
        ], $http);

        $this->assertSame(4, $manager->count());
        $this->assertSame([
            'Initialized telemetry exporter: otlp',
            'Initialized telemetry exporter: dd',
            'SentryExporter: Initialized with endpoint ' . self::SENTRY,
            'Initialized telemetry exporter: sentry',
            'PostHogExporter: Initialized with endpoint https://us.i.posthog.com/batch',
            'Initialized telemetry exporter: ph',
        ], $this->logger->messages());

        $manager->export(ExporterFixtures::event());
        $http->pump();

        $this->assertSame([
            'http://otlp.test/v1/traces',
            'https://http-intake.logs.datadoghq.com/api/v2/logs',
            'https://api.datadoghq.com/api/v1/series',
            self::SENTRY,
            'https://us.i.posthog.com/batch',
        ], $http->urls());
    }

    #[Group('ac-06-10')]
    public function testAnUnknownConfigIsSkippedAndTheOthersAreBuilt(): void
    {
        $manager = TelemetryManager::fromConfigs(['a' => new OtlpExporter('http://h'), 'b' => new \stdClass()], new RecordingHttpChannel());

        $this->assertSame(1, $manager->count());
        $this->assertSame(['Initialized telemetry exporter: a', 'Unknown exporter type: stdClass'], $this->logger->messages());
    }

    /** Task 4: `new Options(exporters: […])` does not validate its entries; anything else is skipped with its type. */
    #[DataProvider('unknownConfigs')]
    #[Group('ac-06-10')]
    public function testAnyEntryThatIsNotAnExporterConfigIsSkippedWithItsType(mixed $config, string $type): void
    {
        $manager = TelemetryManager::fromConfigs(['x' => $config], new RecordingHttpChannel());

        $this->assertSame(0, $manager->count());
        $this->assertSame(['Unknown exporter type: ' . $type], $this->logger->messages());
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unknownConfigs(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'a config array (fromArray() maps these, the constructor does not)' => [['type' => 'otlp', 'endpoint' => 'http://h'], 'array'];
        yield 'a lazy factory' => [static fn(): OtlpExporter => new OtlpExporter('http://h'), 'Closure'];
        yield 'a string' => ['otlp', 'string'];
        yield 'an object' => [new \ArrayObject(), 'ArrayObject'];
    }

    public function testIntegerKeysNameTheExporterLikeJavaScriptObjectKeys(): void
    {
        $http = new RecordingHttpChannel([new HttpResponse(500, 'Internal Server Error', '')]);
        $manager = TelemetryManager::fromConfigs([new OtlpExporter('http://h'), 5 => new SentryExporter('bad')], $http);

        $manager->export(ExporterFixtures::event());
        $http->pump();

        $this->assertSame([
            'Initialized telemetry exporter: 0',
            'Failed to initialize exporter 5: InvalidArgumentException: Invalid Sentry DSN: bad',
            'Telemetry export failed for 0: OTLP export error: Error: OTLP export failed: 500 Internal Server Error',
        ], $this->logger->messages());
    }

    #[Group('ac-06-11')]
    public function testAnInvalidDsnDoesNotStopTheNextExporter(): void
    {
        $http = new RecordingHttpChannel();
        $manager = TelemetryManager::fromConfigs([
            'sentry' => new SentryExporter('https://ABC@o1.ingest.sentry.io/42'),
            'ph' => new PostHogExporter('phc_x'),
        ], $http);

        $manager->export(ExporterFixtures::event());
        $http->pump();

        $this->assertSame(1, $manager->count());
        $this->assertSame(['https://us.i.posthog.com/batch'], $http->urls());
        $this->assertSame([
            'Failed to initialize exporter sentry: InvalidArgumentException: Invalid Sentry DSN: https://ABC@o1.ingest.sentry.io/42',
            'PostHogExporter: Initialized with endpoint https://us.i.posthog.com/batch',
            'Initialized telemetry exporter: ph',
            'PostHogExporter: Sending 1 event(s) for ' . ExporterFixtures::EVENT,
            'PostHog export success - Event: ' . ExporterFixtures::EVENT,
        ], $this->logger->messages());
    }

    #[DataProvider('thrown')]
    #[Group('ac-06-12')]
    public function testAThrowingExporterIsLoggedWithTheTypeScriptMessageAndTheNextOneRuns(\Throwable $error, string $message): void
    {
        $throwing = new readonly class ($error) implements Exporter {
            public function __construct(private \Throwable $error) {}

            #[\Override]
            public function export(Event $event, \Closure $reject): void
            {
                throw $this->error;
            }
        };
        $recording = new class implements Exporter {
            /** @var list<string> */
            public array $calls = [];

            #[\Override]
            public function export(Event $event, \Closure $reject): void
            {
                $this->calls[] = $event->id;
            }
        };

        (new TelemetryManager(['first' => $throwing, 'second' => $recording]))->export(ExporterFixtures::event());

        $this->assertSame(['Telemetry export failed for first: ' . $message], $this->logger->messages());
        $this->assertSame([ExporterFixtures::EVENT], $recording->calls);
    }

    /** @return iterable<string, array{\Throwable, string}> */
    public static function thrown(): iterable
    {
        yield 'a PHP exception: its message (TS error.message)' => [new \RuntimeException('boom'), 'boom'];
        yield 'a JavaScript error: its message without the name' => [new JsError('TypeError', 'value.replace is not a function'), 'value.replace is not a function'];
    }

    #[Group('ac-06-12')]
    public function testARejectionIsLoggedUnderTheNameOfItsExporter(): void
    {
        $rejecting = new class implements Exporter {
            #[\Override]
            public function export(Event $event, \Closure $reject): void
            {
                $reject('nope ' . $event->id);
            }
        };

        (new TelemetryManager(['a' => $rejecting, 7 => $rejecting]))->export(ExporterFixtures::event());

        $this->assertSame([
            'Telemetry export failed for a: nope ' . ExporterFixtures::EVENT,
            'Telemetry export failed for 7: nope ' . ExporterFixtures::EVENT,
        ], $this->logger->messages());
    }

    public function testAManagerWithoutExportersDoesNothing(): void
    {
        $manager = new TelemetryManager([]);

        $manager->export(ExporterFixtures::event());

        $this->assertSame(0, $manager->count());
        $this->assertSame([], $this->logger->messages());
    }

    /** Review Focus 4: values JSON cannot carry as they are. */
    public function testValuesThatJsonCannotCarryNeverStopTheOtherExporters(): void
    {
        $http = new RecordingHttpChannel();
        $manager = TelemetryManager::fromConfigs([
            'otlp' => new OtlpExporter('http://otlp.test'),
            'dd' => new DatadogExporter('k', 'datadoghq.com', 'svc'),
            'sentry' => new SentryExporter('https://abc@o1.ingest.sentry.io/42', enableTracing: true),
            'ph' => new PostHogExporter('phc_x', enableAITracing: true),
        ], $http);
        $self = ['a' => 1];
        $self['self'] = &$self;
        $event = ExporterFixtures::errorEvent();
        $event->resourceName = "caf\xE9";
        $event->tags = ['t' => "x\xFF"];
        $event->properties = ['n' => NAN, 'i' => INF];
        $event->error = ['f' => static fn(): int => 1, 'r' => STDIN];
        $event->parameters = $self;

        $manager->export($event);
        $http->pump();

        foreach ($http->requests as $request) {
            foreach (explode("\n", rtrim($request->body, "\n")) as $line) {
                $this->assertJson($line, $request->url);
            }
        }
        $failures = array_values(array_filter($this->logger->messages(), static fn(string $line): bool => preg_match('/failed|export error|network error/', $line) === 1));
        $this->assertSame(['PostHog export error: UnexpectedValueException: Maximum nesting depth of 512 exceeded'], $failures, 'only PostHog sends parameters, and it logs exactly one line');
        $this->assertSame([
            'http://otlp.test/v1/traces',
            'https://http-intake.logs.datadoghq.com/api/v2/logs',
            'https://api.datadoghq.com/api/v1/series',
            self::SENTRY,
            self::SENTRY,
            self::SENTRY,
        ], $http->urls(), 'OTLP, both Datadog requests and the Sentry log, transaction and error event are sent');
        $this->assertCount(6, $http->requests);
        $this->assertStringContainsString("caf\u{FFFD}", $http->requests[0]->body);
        $this->assertStringContainsString('"properties":{"n":null,"i":null}', $http->requests[1]->body);
        $this->assertStringContainsString('"error":{"r":"[Resource: stream]"}', $http->requests[1]->body);
        $this->assertStringContainsString('"agentcat.t":"x' . "\u{FFFD}" . '"', $http->requests[5]->body);
    }

    public function testInstallIsFirstWinsAndWarnsOnceForADifferentSet(): void
    {
        $queue = $this->queue();
        $first = ['otlp' => new OtlpExporter('http://a')];

        TelemetryManager::install($queue, null);
        $this->assertSame([], $this->logger->messages());
        TelemetryManager::install($queue, $first);
        $manager = $queue->exporterSink();
        TelemetryManager::install($queue, ['otlp' => new OtlpExporter('http://a')]);
        TelemetryManager::install($queue, ['otlp' => new OtlpExporter('http://b')]);
        TelemetryManager::install($queue, ['x' => new OtlpExporter('http://c')]);
        TelemetryManager::install($queue, null);

        $this->assertInstanceOf(TelemetryManager::class, $manager);
        $this->assertSame($manager, $queue->exporterSink());
        $this->assertSame([
            'Initialized telemetry exporter: otlp',
            'Initialized telemetry with 1 exporters',
            'WARN: AgentCat exporters were set up by an earlier track(); the exporters of this call are ignored',
        ], $this->logger->messages());
    }

    public function testInstallSendsThroughTheChannelOfTheQueue(): void
    {
        $http = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $http);

        TelemetryManager::install($queue, ['otlp' => new OtlpExporter('http://a')]);
        $queue->exporterSink()->export(ExporterFixtures::event());

        $this->assertSame(['http://a/v1/traces'], $http->urls());
    }

    /** Spec conflict 5: `exporters: []` is TypeScript `{}`, which is truthy: an empty manager. */
    public function testAnEmptySetInstallsAnEmptyManager(): void
    {
        $queue = $this->queue();

        TelemetryManager::install($queue, []);
        TelemetryManager::install($queue, []);

        $manager = $queue->exporterSink();
        $this->assertInstanceOf(TelemetryManager::class, $manager);
        $this->assertSame(0, $manager->count());
        $this->assertSame(['Initialized telemetry with 0 exporters'], $this->logger->messages());
    }

    /** Spec conflict 5: the count is of the entries that reached `Options::$exporters`, built or not. */
    public function testTheInstallCountIsOfTheEntriesGiven(): void
    {
        TelemetryManager::install($this->queue(), ['otlp' => new OtlpExporter('http://a'), 'bad' => 'otlp']);

        $this->assertSame([
            'Initialized telemetry exporter: otlp',
            'Unknown exporter type: string',
            'Initialized telemetry with 2 exporters',
        ], $this->logger->messages());
    }

    public function testInstallLeavesAnotherSinkAlone(): void
    {
        $recorder = new RecordingExporter();
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $recorder, channel: new RecordingHttpChannel());

        TelemetryManager::install($queue, ['otlp' => new OtlpExporter('http://a')]);

        $this->assertSame($recorder, $queue->exporterSink());
        $this->assertSame([], $this->logger->messages());
    }

    /** Ruling P3: a set that cannot be serialized counts as different; install() never throws (D32). */
    public function testASetThatCannotBeSerializedCountsAsDifferentAndNeverThrows(): void
    {
        $queue = $this->queue();
        $lazy = ['otlp' => static fn(): OtlpExporter => new OtlpExporter('http://a')];

        TelemetryManager::install($queue, $lazy);
        TelemetryManager::install($queue, $lazy);
        TelemetryManager::install($queue, $lazy);

        $this->assertInstanceOf(TelemetryManager::class, $queue->exporterSink());
        $this->assertSame([
            'Unknown exporter type: Closure',
            'Initialized telemetry with 1 exporters',
            'WARN: AgentCat exporters were set up by an earlier track(); the exporters of this call are ignored',
        ], $this->logger->messages());
    }

    /** Ruling P3: a set whose serialization warns counts as different, and the warning reaches no output. */
    public function testASetWhoseSerializationWarnsCountsAsDifferent(): void
    {
        $queue = $this->queue();
        TelemetryManager::install($queue, ['otlp' => new OtlpExporter('http://a')]);

        TelemetryManager::install($queue, ['otlp' => new OtlpExporter('http://a'), 'odd' => new MissingSleepProperty()]);

        $this->assertSame([
            'Initialized telemetry exporter: otlp',
            'Initialized telemetry with 1 exporters',
            'WARN: AgentCat exporters were set up by an earlier track(); the exporters of this call are ignored',
        ], $this->logger->messages());
    }

    private function queue(): EventQueue
    {
        return new EventQueue(transport: new RecordingTransport(), channel: new RecordingHttpChannel());
    }
}
