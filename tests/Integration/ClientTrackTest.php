<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration;

use AgentCat\Client;
use AgentCat\Event;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Identity;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\SignalDrain;
use AgentCat\Internal\Engine\Libraries;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\RetryPolicy;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\BuilderMembers;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\ToolCall;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** `Client::track()` on real mcp/sdk Builders, end to end through the adapter. */
final class ClientTrackTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string NO_PROJECT = 'Warning: No projectId provided and no exporters configured. Events will not be sent anywhere.';

    private const string ALREADY_TRACKED = '[SESSION DEBUG] track() - Server already being tracked, skipping initialization';

    private const string SIGNALS_INSTALLED = 'WARN: AgentCat signal handlers are already installed; disableSignalHandlers is ignored';

    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private Environment $environment;

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private RecordingExporter $exporter;

    private EventQueue $queue;

    private Client $client;

    /** @var array<int, (callable(int, mixed): mixed)|int> the SIGTERM and SIGINT handlers before the test */
    private array $savedHandlers = [];

    private ?bool $savedAsyncSignals = null;

    #[\Override]
    protected function setUp(): void
    {
        if (function_exists('pcntl_signal_get_handler')) {
            foreach ([\SIGTERM, \SIGINT] as $signal) {
                $this->savedHandlers[$signal] = pcntl_signal_get_handler($signal);
            }
            $this->savedAsyncSignals = pcntl_async_signals();
        }
        $this->environment = Environment::clear('AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_ENABLED', 'APP_ENV');
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: $this->transport, exporters: $this->exporter);
        $this->client = new Client(ClientSeams::of($this->queue, static fn(): bool => true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->savedHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($this->savedAsyncSignals !== null) {
            pcntl_async_signals($this->savedAsyncSignals);
        }
        TrackedServers::resetIncomplete(); // a failed track() marks the process
        TrackedServers::resetSkipped(); // so does a track() while AgentCat is off
        BridgeConfig::reset();
        Log::reset();
        $this->environment->restore();
    }

    #[RequiresPhpExtension('pcntl')]
    public function testTrackWarnsOncePerProcessWhenTheSignalHandlersAreInstalledAlready(): void
    {
        $queue = new EventQueue(transport: $this->transport, signalDrain: $this->installedDrain());
        $client = new Client(ClientSeams::of($queue, static fn(): bool => true));

        $client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableSignalHandlers: true));
        $this->assertContains(self::SIGNALS_INSTALLED, $this->logger->messages(), 'the first call warns');
        $client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableSignalHandlers: true));
        $client->track(TrackingFixture::builder(), self::PROJECT);

        $this->assertCount(1, array_keys($this->logger->messages(), self::SIGNALS_INSTALLED, true));
    }

    #[RequiresPhpExtension('pcntl')]
    public function testTrackSaysNothingWhenTheSwitchIsOffOrThereIsNothingToDisable(): void
    {
        $term = pcntl_signal_get_handler(\SIGTERM);
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, null, static fn(string $f): bool => true, 'cli');
        $client = new Client(ClientSeams::of(new EventQueue(transport: $this->transport, signalDrain: $drain), static fn(): bool => true));

        $client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableSignalHandlers: true));
        $drain->install();

        $this->assertNotContains(self::SIGNALS_INSTALLED, $this->logger->messages());
        $this->assertSame($term, pcntl_signal_get_handler(\SIGTERM), 'the switch kept the drain out');
        $this->client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableSignalHandlers: true)); // a queue without a drain
        $this->assertNotContains(self::SIGNALS_INSTALLED, $this->logger->messages());
    }

    #[Group('ac-01-1')]
    #[Group('ac-02-1')]
    #[Group('ac-01-4')]
    public function testTrackInstrumentsTheBuilderAndReturnsIt(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->assertSame($builder, $this->client->track($builder, self::PROJECT));

        $this->assertSame([
            'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0',
        ], $this->logger->messages());
        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($this->harness($builder)->listTools()));
        $this->assertFalse(TrackedServers::incomplete());
    }

    #[Group('ac-06-14')]
    public function testTheCompleteLineCountsTheExporters(): void
    {
        $queue = new EventQueue(transport: $this->transport, channel: new RecordingHttpChannel());
        $client = new Client(ClientSeams::of($queue, static fn(): bool => true));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $client->track($builder, self::PROJECT, new Options(exporters: [
            'otlp' => new OtlpExporter('http://otlp.test'),
            'ph' => new PostHogExporter('phc_x'),
        ]));

        $this->assertContains(
            'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=2',
            $this->logger->messages(),
        );
    }

    #[Group('ac-01-4')]
    public function testTheCompleteLineShowsTheOptions(): void
    {
        $builder = TrackingFixture::builder();

        $this->client->track($builder, self::PROJECT, new Options(enableReportMissing: false, enableTracing: false, enableToolCallContext: false));

        $this->assertSame(
            'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=false context=false reportMissing=false exporters=0',
            $this->logger->messages()[1] ?? null,
        );
    }

    /** @return iterable<string, array{\Closure(): object, string}> */
    public static function unsupportedServers(): iterable
    {
        yield 'any object' => [static fn(): object => new \stdClass(), '(none)'];
        yield 'a built mcp/sdk server' => [static fn(): object => Server::builder()->build(), 'mcpSdkServer'];
    }

    /** @param \Closure(): object $make */
    #[DataProvider('unsupportedServers')]
    #[Group('ac-01-2')]
    #[Group('ac-02-2')]
    public function testAnUnsupportedObjectIsReturnedWithTheShapeLine(\Closure $make, string $signals): void
    {
        $server = $make();

        $this->assertSame($server, $this->client->track($server, self::PROJECT));

        $this->assertSame([
            ['level' => LogLevel::WARNING, 'message' => 'AgentCat SDK Compatibility: unrecognized server shape | signals ' . $signals . '. ' . Libraries::supportMatrix()],
            ['level' => LogLevel::WARNING, 'message' => 'Warning: Failed to track server - UnexpectedValueException: AgentCat SDK compatibility error: server object does not match any supported MCP SDK shape. ' . Libraries::supportMatrix()],
        ], $this->logger->records);
    }

    public function testTheSetupLineNamesTheLibraryOfTheServer(): void
    {
        $this->client->track(TrackingFixture::builder(), self::PROJECT);

        $this->assertSame('AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder', $this->logger->messages()[0] ?? null);
    }

    #[Group('ac-01-2')]
    public function testABuiltBuilderIsLeftAlone(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $server = $builder->build();

        $this->assertSame($builder, $this->client->track($builder, self::PROJECT));

        $this->assertSame([
            'AgentCat SDK Compatibility: ' . McpSdkAdapter::SUPPORT_MATRIX,
            'Warning: Failed to track server - UnexpectedValueException: AgentCat SDK compatibility error: the mcp/sdk Builder was already built. ' . McpSdkAdapter::SUPPORT_MATRIX,
        ], $this->logger->messages());
        $this->assertSame(['echo'], $this->names($this->initialized($server)->listTools()));
        $this->assertSame(['echo'], $this->names($this->harness($builder)->listTools()), 'no AgentCat handler was added');
    }

    #[Group('ac-01-3')]
    public function testAnInternalFailureReturnsTheServerAndLogsIt(): void
    {
        $client = new Client(ClientSeams::of($this->queue, static fn(): bool => throw new \RuntimeException('boom')));
        $builder = TrackingFixture::builder();

        $this->assertSame($builder, $client->track($builder, self::PROJECT));

        $this->assertSame(['Warning: Failed to track server - RuntimeException: boom'], $this->logger->messages());
        $this->assertTrue(TrackedServers::incomplete(), 'publishCustomEvent() then logs instead of throwing');
    }

    #[Group('ac-01-3')]
    public function testAFailureWhileInstallingLeavesTheBuilderUntouched(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        // The member becomes unreadable after the probe, while AgentCat logs its start line: install() then fails.
        $remove = static function () use ($builder): void {
            BuilderMembers::uninitialize('referenceHandler')($builder);
        };
        $logger = new class ($remove) extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /** @param \Closure(): void $onStart */
            public function __construct(private readonly \Closure $onStart) {}

            /** @param mixed[] $context */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
                if (str_starts_with((string) $message, 'AgentCat setup started')) {
                    ($this->onStart)();
                }
            }
        };
        Log::setLogger($logger);

        $this->assertSame($builder, $this->client->track($builder, self::PROJECT));

        $this->assertSame([
            'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            'Warning: Failed to track server - Error: Typed property Mcp\Server\Builder::$referenceHandler must not be accessed before initialization',
        ], $logger->lines);
        $this->assertNull(BuilderAccess::container($builder), 'AgentCat set no container');
        $harness = $this->harness($builder); // build() reads the missing member with ??, which tolerates it
        $this->assertSame(['echo'], $this->names($harness->listTools()));
        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($harness->callTool('echo', ['msg' => 'hi'])), 'content', 0, 'text'));
        $this->assertTrue($this->queue->flush());
        $this->assertSame([], $this->exporter->events);
    }

    /** @return iterable<string, array{?string}> */
    public static function noProject(): iterable
    {
        yield 'null without AGENTCAT_PROJECT_ID' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('noProject')]
    #[Group('ac-01-5')]
    #[Group('ac-01-6')]
    #[Group('ac-03-8')]
    public function testWithoutAProjectEventsReachOnlyTheExporters(?string $project): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder, $project);

        $this->assertSame([
            'AgentCat setup started | project (telemetry-only) | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            self::NO_PROJECT,
            'AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=0',
        ], $this->logger->messages());
        $harness = $this->harness($builder);
        $this->assertContains(GetMoreTools::NAME, $this->names($harness->listTools()));
        $harness->callTool('echo', ['msg' => 'hi']);
        $this->assertNull($this->event()->projectId);
        $this->assertSame([], $this->transport->deliveries);
    }

    #[Group('ac-01-5')]
    public function testANullProjectReadsAgentCatProjectId(): void
    {
        $project = 'proj_' . str_repeat('b', 27);
        putenv('AGENTCAT_PROJECT_ID=' . $project);
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder);

        $this->assertSame([
            'AgentCat setup started | project ' . $project . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            'AgentCat setup complete | project ' . $project . ' | tracing=true context=true reportMissing=true exporters=0',
        ], $this->logger->messages());
        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);
        $this->assertSame($project, $this->event()->projectId);
        $this->assertCount(1, $this->transport->deliveries);
    }

    /** A bridge's configured project (a cached Laravel configuration) comes before AGENTCAT_PROJECT_ID. */
    #[Group('ac-01-5')]
    public function testANullProjectReadsTheConfiguredProjectFirst(): void
    {
        $project = 'proj_' . str_repeat('c', 27);
        putenv('AGENTCAT_PROJECT_ID=proj_' . str_repeat('b', 27));
        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => $project], false);
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder);

        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);
        $this->assertSame($project, $this->event()->projectId);
    }

    #[Group('ac-01-6')]
    public function testAnEmptyProjectStaysTelemetryOnlyWithAgentCatProjectIdSet(): void
    {
        putenv('AGENTCAT_PROJECT_ID=proj_' . str_repeat('b', 27));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder, '');

        $this->assertContains(self::NO_PROJECT, $this->logger->messages());
        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);
        $this->assertNull($this->event()->projectId);
        $this->assertSame([], $this->transport->deliveries);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedProjects(): iterable
    {
        yield 'too short' => ['proj_short'];
        yield 'one character too many' => ['proj_' . str_repeat('a', 28)];
        yield 'wrong prefix' => ['prj_' . str_repeat('a', 28)];
        yield 'not letters or digits' => ['proj_' . str_repeat('a', 26) . '-'];
        yield 'a trailing newline' => ['proj_' . str_repeat('a', 27) . "\n"];
    }

    #[DataProvider('malformedProjects')]
    #[Group('ac-07-15')]
    public function testAMalformedProjectIdIsWarnedAboutAndStillTracked(string $project): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder, $project);

        $this->assertSame([
            'AgentCat setup started | project ' . $project . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            sprintf('WARN: Project ID "%s" does not match proj_ followed by 27 letters or digits; the AgentCat API drops its events', $project),
            'AgentCat setup complete | project ' . $project . ' | tracing=true context=true reportMissing=true exporters=0',
        ], $this->logger->messages());
        $this->assertContains(GetMoreTools::NAME, $this->names($this->harness($builder)->listTools()));
    }

    #[Group('ac-01-7')]
    #[Group('ac-02-4')]
    public function testTrackingABuilderTwiceKeepsTheFirstTracking(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT, new Options(identify: static fn(ToolCall $call): Identity => new Identity('first')));

        $this->assertSame($builder, $this->client->track($builder, self::PROJECT, new Options(
            enableReportMissing: false,
            identify: static fn(ToolCall $call): Identity => new Identity('second'),
        )));

        $started = 'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder';
        $this->assertSame([$started, 'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0', $started, self::ALREADY_TRACKED], $this->logger->messages());
        $harness = $this->harness($builder);
        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($harness->listTools()));
        $result = HttpHarness::result($harness->callTool('echo', ['msg' => 'hi']));
        $texts = $this->texts($result);
        $this->assertCount(1, array_filter($texts, static fn(mixed $text): bool => is_string($text) && str_starts_with($text, Constants::MINT_BACK_HEADER_ISSUED)));
        $this->assertSame('echo: hi', $texts[1] ?? null);
        $this->assertSame('first', $this->event()->identifyActorGivenId);
    }

    #[Group('ac-01-7')]
    public function testABuilderIsTrackedOnceWhicheverClientTracksItAgain(): void
    {
        $otherTransport = new RecordingTransport();
        $otherExporter = new RecordingExporter();
        $other = new Client(ClientSeams::of(new EventQueue(transport: $otherTransport, exporters: $otherExporter), static fn(): bool => true));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT, new Options(identify: static fn(ToolCall $call): Identity => new Identity('first')));

        $this->assertSame($builder, $other->track($builder, self::PROJECT, new Options(identify: static fn(ToolCall $call): Identity => new Identity('second'))));

        $started = 'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder';
        $this->assertSame([$started, 'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0', $started, self::ALREADY_TRACKED], $this->logger->messages());
        $harness = $this->harness($builder);
        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($harness->listTools()));
        $texts = $this->texts(HttpHarness::result($harness->callTool('echo', ['msg' => 'hi'])));
        $this->assertCount(1, array_filter($texts, static fn(mixed $text): bool => is_string($text) && str_starts_with($text, Constants::MINT_BACK_HEADER_ISSUED)));
        $this->assertSame('echo: hi', $texts[1] ?? null);
        $this->assertSame('first', $this->event()->identifyActorGivenId, 'the first client owns the Builder');
        $this->assertSame($this->queue, TrackedServers::get($builder)?->publisher()->queue(), "the server keeps its owner's publisher");
        $this->assertTrue($other->flush());
        $this->assertSame([], $otherExporter->events);
        $this->assertSame([], $otherTransport->deliveries);
    }

    #[Group('ac-01-7')]
    public function testTrackingATrackedBuilderAfterItWasBuiltChangesNothing(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT);
        $harness = $this->harness($builder);

        $this->assertSame($builder, $this->client->track($builder, self::PROJECT));

        $this->assertSame([
            'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0',
            'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder,built',
            self::ALREADY_TRACKED,
        ], $this->logger->messages());
        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($harness->listTools()));
    }

    #[Group('ac-01-9')]
    #[Group('ac-02-5')]
    public function testSeveralBuildersKeepTheirOwnStateAndShareOneQueue(): void
    {
        $gone = [];
        foreach (['u0', 'u1', 'u2'] as $user) {
            $builder = TrackingFixture::builder();
            Tools::echo($builder);
            $this->client->track($builder, self::PROJECT, new Options(identify: static fn(ToolCall $call): Identity => new Identity($user)));
            $this->harness($builder)->callTool('echo', ['msg' => 'hi']); // a fresh server: rebuild on demand
            $gone[] = \WeakReference::create($builder);
            unset($builder);
        }

        $this->assertTrue($this->queue->flush());
        $this->assertSame(['u0', 'u1', 'u2'], array_map(static fn(Event $event): ?string => $event->identifyActorGivenId, $this->exporter->events));
        $this->assertCount(3, $this->transport->deliveries, 'one shared queue');
        $this->assertCount(3, $this->rebuilt());

        gc_collect_cycles();

        foreach ($gone as $reference) {
            $this->assertNull($reference->get(), 'AgentCat keeps no state for a Builder that is gone');
        }
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT);
        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);
        $this->assertCount(4, $this->rebuilt(), 'a new Builder rebuilds at its first call');
    }

    #[Group('ac-01-12')]
    #[Group('ac-01-55')]
    #[Group('ac-01-59')]
    public function testTheLastApiBaseUrlWins(): void
    {
        $this->client->track(TrackingFixture::builder(), self::PROJECT, new Options(apiBaseUrl: 'https://u1.test'));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT, new Options(apiBaseUrl: 'https://u2.test'));
        $this->client->track(TrackingFixture::builder(), self::PROJECT); // no option and no variable: no change
        $this->client->track(TrackingFixture::builder(), self::PROJECT, new Options(apiBaseUrl: '')); // skipped

        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);

        $this->assertTrue($this->queue->flush());
        $this->assertSame(['https://u2.test/v1/public/events/'], $this->urls());
    }

    /** @return iterable<string, array{string}> */
    public static function urlVariables(): iterable
    {
        yield 'AGENTCAT_API_URL' => ['AGENTCAT_API_URL'];
        yield 'MCPCAT_API_URL' => ['MCPCAT_API_URL'];
    }

    #[DataProvider('urlVariables')]
    public function testAnApiUrlVariableSetBeforeTrackIsUsed(string $variable): void
    {
        putenv($variable . '=https://env.test');
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder, self::PROJECT);
        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);

        $this->assertTrue($this->queue->flush());
        $this->assertSame(['https://env.test/v1/public/events/'], $this->urls());
    }

    /** A bridge's configured URL (a cached Laravel configuration) is set by a track() without the option, like a variable. */
    public function testAConfiguredApiUrlIsUsedByATrackWithoutTheOption(): void
    {
        $this->queue->configure('https://earlier.test'); // an earlier track() with its own option
        BridgeConfig::configure(['AGENTCAT_API_URL' => 'https://configured.test'], false);
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->client->track($builder, self::PROJECT);
        $this->harness($builder)->callTool('echo', ['msg' => 'hi']);

        $this->assertTrue($this->queue->flush());
        $this->assertSame(['https://configured.test/v1/public/events/'], $this->urls());
    }

    public function testUnderPhpUnitTheDefaultClientLeavesTheBuilderAlone(): void
    {
        $client = new Client(ClientSeams::of($this->queue));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $this->assertSame($builder, $client->track($builder, self::PROJECT));

        $this->assertSame([], $this->logger->messages());
        $this->assertSame(['echo'], $this->names($this->harness($builder)->listTools()));
    }

    public function testAgentCatEnabledTurnsTheDefaultClientOn(): void
    {
        putenv('AGENTCAT_ENABLED=true');
        $client = new Client(ClientSeams::of($this->queue));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);

        $client->track($builder, self::PROJECT);

        $this->assertCount(2, $this->logger->messages());
        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($this->harness($builder)->listTools()));
    }

    public function testTheFirstBuildersLoggerReceivesEverySdkLine(): void
    {
        Log::reset();
        $first = new MemoryLogger();
        $second = new MemoryLogger();

        $this->client->track(TrackingFixture::builder()->setLogger($first), self::PROJECT);
        $this->client->track(TrackingFixture::builder()->setLogger($second), self::PROJECT);

        $started = 'AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder';
        $complete = 'AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0';
        $this->assertSame([$started, $complete, $started, $complete], $first->messages());
        $this->assertSame([], $second->messages());
        $this->assertSame([], $this->logger->messages());
    }

    public function testTheBuildersLoggerReceivesTheCompatibilityLine(): void
    {
        Log::reset();
        $builderLogger = new MemoryLogger();
        $builder = TrackingFixture::builder()->setLogger($builderLogger);
        $builder->build();

        $this->client->track($builder, self::PROJECT);

        $this->assertSame('AgentCat SDK Compatibility: ' . McpSdkAdapter::SUPPORT_MATRIX, $builderLogger->messages()[0] ?? null);
    }

    #[Group('ac-03-1')]
    #[MaximumDuration(5000)]
    public function testATrackedCallIsDeliveredToTheCollector(): void
    {
        $collector = MockCollector::start();

        try {
            $base = $collector->scenario();
            $client = new Client(ClientSeams::of(new EventQueue(transport: new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]))), static fn(): bool => true));
            $builder = TrackingFixture::builder();
            Tools::echo($builder);
            $client->track($builder, self::PROJECT, new Options(apiBaseUrl: $base));

            $this->harness($builder)->callTool('echo', ['msg' => 'hi']);

            $this->assertTrue($client->flush(5.0));
            $requests = $collector->requests($base);
            $this->assertCount(1, $requests);
            $this->assertSame('POST', $requests[0]->method);
            $this->assertIsString($requests[0]->body);
            $body = Json::decode($requests[0]->body);
            $this->assertSame(
                ['echo', 'harness-server', 'harness-client', 'PHP', Constants::TOOLS_CALL_EVENT_TYPE, 'minted', 'tools/call'],
                [
                    JsonAssert::at($body, 'resource_name'),
                    JsonAssert::at($body, 'server_name'),
                    JsonAssert::at($body, 'client_name'),
                    JsonAssert::at($body, 'sdk_language'),
                    JsonAssert::at($body, 'event_type'),
                    JsonAssert::at($body, 'tags', Constants::TAG_SESSION_SOURCE),
                    JsonAssert::at($body, 'parameters', 'request', 'method'),
                ],
            );
        } finally {
            $collector->stop();
        }
    }

    /** Builds the server and runs the handshake. */
    private function harness(Builder $builder): HttpHarness
    {
        return $this->initialized($builder->build());
    }

    private function initialized(Server $server): HttpHarness
    {
        $harness = new HttpHarness($server);
        $harness->initialize();

        return $harness;
    }

    /** @return list<mixed> the tool names of a listing, in order */
    private function names(\stdClass $listing): array
    {
        $tools = $listing->tools ?? null;
        $this->assertIsList($tools);

        return array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $tools);
    }

    /** @return list<mixed> the `text` of every content block */
    private function texts(\stdClass $result): array
    {
        $content = $result->content ?? null;
        $this->assertIsList($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), $content);
    }

    /** Delivers everything queued; fails unless exactly one event was exported. */
    private function event(): Event
    {
        $this->assertTrue($this->queue->flush());
        $this->assertCount(1, $this->exporter->events);

        return $this->exporter->events[0];
    }

    /**
     * @return list<string>
     *
     * @phpstan-impure
     */
    private function rebuilt(): array
    {
        return array_values(array_filter($this->logger->messages(), static fn(string $line): bool => $line === self::REBUILT));
    }

    /** @return list<string> */
    private function urls(): array
    {
        return array_map(static fn(Delivery $delivery): string => $delivery->url, $this->transport->deliveries);
    }

    private function installedDrain(): SignalDrain
    {
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, null, static fn(string $f): bool => true, 'cli');
        $drain->install();

        return $drain;
    }
}
