<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Laravel\AgentCatServiceProvider;
use AgentCat\Laravel\Internal\ServerTracker;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\InvokableIdentify;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Testing\PendingCommand;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServiceProviderTest extends LaravelTestCase
{
    private const string OTHER_PROJECT = 'proj_ppppppppppppppppppppppppppp';

    private RecordingTransport $transport;

    private ?Environment $agentcatEnvironment = null;

    /** True when a `DefineEnvironment` method of the test sets the whole configuration (it can run first). */
    private bool $ownConfiguration = false;

    #[\Override]
    protected function setUp(): void
    {
        // The configuration decides, not the variables of the process that runs the tests.
        $this->agentcatEnvironment = Environment::clear('AGENTCAT_ENABLED', 'AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'AGENTCAT_LOG_CHANNEL');
        TestMode::reset();
        parent::setUp();
        EventQueue::setGlobal(new EventQueue(transport: $this->transport = new RecordingTransport()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        TestMode::reset();
        $this->agentcatEnvironment?->restore();
    }

    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);
        if (!$this->ownConfiguration) {
            config(['agentcat.enabled' => true, 'agentcat.project_id' => LaravelTrackingFixture::PROJECT]);
        }
    }

    /** Review Focus 3: config/agentcat.php with missing and mistyped `env()` values, before the providers boot. */
    public function defineBadConfiguration(): void
    {
        $this->ownConfiguration = true;
        config([
            'agentcat.enabled' => true,
            'agentcat.project_id' => null,
            'agentcat.enable_tracing' => 'yes',
            'agentcat.identify' => 'App\Missing\Identify',
            'agentcat.exporters' => ['otlp' => null],
            'agentcat.unknown_key' => 1,
        ]);
    }

    /** Review Focus 3: `AGENTCAT_ENABLED=maybe`, which `env()` gives the configuration too, before the providers boot. */
    public function defineAnUnknownEnabledWord(): void
    {
        $this->ownConfiguration = true;
        putenv('AGENTCAT_ENABLED=maybe');
        config(['agentcat.enabled' => 'maybe', 'agentcat.project_id' => LaravelTrackingFixture::PROJECT]);
    }

    /** @return list<class-string> */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), AgentCatServiceProvider::class];
    }

    public function testEveryResolvedServerIsTrackedWithNoCode(): void
    {
        $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
    }

    public function testBoostIsExcludedByDefaultAndConfigExcludesMore(): void
    {
        $this->assertSame(['Laravel\Boost\Mcp\Boost'], config('agentcat.exclude'));
        config(['agentcat.exclude' => [ConformanceServer::class]]);

        $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertNotSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
    }

    public function testAnExcludedClassAlsoExcludesItsSubclasses(): void
    {
        config(['agentcat.exclude' => ['App\Mcp\Missing', Server::class]]);

        $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertNotSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
    }

    public function testAServerCanGoToItsOwnProject(): void
    {
        config(['agentcat.projects' => [ConformanceServer::class => self::OTHER_PROJECT]]);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(self::OTHER_PROJECT, $this->deliveredField('project_id'));
    }

    public function testTheFirstProjectEntryThatTheServerIsAnInstanceOfWins(): void
    {
        config(['agentcat.projects' => ['App\Mcp\Missing' => 'proj_mmmmmmmmmmmmmmmmmmmmmmmmmmm', Server::class => self::OTHER_PROJECT, ConformanceServer::class => 'proj_ccccccccccccccccccccccccccc']]);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(self::OTHER_PROJECT, $this->deliveredField('project_id'));
    }

    public function testAServerWithoutAProjectEntryGoesToTheProjectId(): void
    {
        config(['agentcat.projects' => ['App\Mcp\Missing' => self::OTHER_PROJECT]]);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(LaravelTrackingFixture::PROJECT, $this->deliveredField('project_id'));
    }

    public function testTheLogLinesGoToTheConfiguredChannel(): void
    {
        $path = $this->laravelDirectory . '/agentcat-channel.log';
        config(['logging.channels.agentcat-test' => ['driver' => 'single', 'path' => $path], 'agentcat.log_channel' => 'agentcat-test']);

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', (string) file_get_contents($path));
        $this->assertStringNotContainsString('AgentCat', $this->defaultChannelLog());
    }

    /** @return iterable<string, array{mixed}> */
    public static function defaultChannels(): iterable
    {
        yield 'unset' => [null];
        yield 'blank' => [''];
        yield 'not a name' => [5];
    }

    #[DataProvider('defaultChannels')]
    public function testWithoutALogChannelNameTheLinesGoToTheDefaultChannel(mixed $channel): void
    {
        config(['agentcat.log_channel' => $channel]);

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', $this->defaultChannelLog());
    }

    public function testAfterAFakeTheNextServerRoutesTheLinesToTheChannel(): void
    {
        AgentCat::fake();
        (new LaravelHarness(ConformanceServer::class))->listTools();
        AgentCat::setClient(null);

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', $this->defaultChannelLog());
    }

    public function testOneTrackerServesTheApplication(): void
    {
        $app = Container::getInstance();

        $this->assertSame($app->make(ServerTracker::class), $app->make(ServerTracker::class));
    }

    public function testBootingTracksNothing(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $command = $this->artisan('list');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0)->run();

        $this->assertSame([], $logger->messages());
        $this->assertFalse(EventQueue::global()->hasWork());
    }

    public function testTheContainerGivesTheSharedClient(): void
    {
        $app = Container::getInstance();

        $this->assertSame(AgentCat::client(), $app->make(Client::class));
        $fake = AgentCat::fake();
        $this->assertSame($fake->client(), $app->make(Client::class)); // a binding, not a singleton
    }

    public function testAFakeRecordsTheCallsOfEveryServerWhateverTheConfigSays(): void
    {
        $fake = AgentCat::fake();
        config(['agentcat.enabled' => false]);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi']);

        $fake->assertTrackedCount(1);
        $this->assertSame([], $this->transport->deliveries);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function untracked(): iterable
    {
        yield 'an excluded server' => [['agentcat.exclude' => [ConformanceServer::class]]];
        yield 'AgentCat off' => [['agentcat.enabled' => false]];
        yield 'agentcat.enabled unset in a test environment (D31)' => [['agentcat.enabled' => null]];
    }

    /**
     * An untracked server keeps laravel/mcp's own method classes and answers exactly like a server that the container
     * never built (no `afterResolving` callback, so no AgentCat at all).
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('untracked')]
    public function testAnUntrackedServerKeepsItsMethodsAndItsAnswers(array $config): void
    {
        config($config);
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $this->assertSame($this->answers(static fn(HttpTransport $transport): Server => new ConformanceServer($transport)), $this->answers(static fn(HttpTransport $transport): mixed => Container::getInstance()->make(ConformanceServer::class, ['transport' => $transport])));
        $this->assertSame([], $logger->messages());
        $this->assertFalse(EventQueue::global()->hasWork());
    }

    /** Review Focus 3: boot, resolve, `tools/call` and flush with the bad values. */
    #[DefineEnvironment('defineBadConfiguration')]
    public function testBadConfigValuesLogOneLineEachAndNothingThrows(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $harness = new LaravelHarness(ConformanceServer::class);

        $harness->listTools();
        $answer = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $this->assertTrue(EventQueue::global()->flush(1.0));

        $this->assertSame('echo: hi', JsonAssert::at($answer, 'result', 'content', 1, 'text'));
        $started = 'AgentCat setup started | project (telemetry-only) | sdk laravel-mcp/server | signals laravelMcpServer';
        $complete = 'AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=0';
        $own = 'ERROR: Tool "own" already declares a \'session_id\' parameter.'; // the fixture's own tool, for every server
        $this->assertSame([
            'WARN: Unknown AgentCat option "agentcat.unknown_key" ignored', // once: the options serve every server
            'WARN: AgentCat option "enableTracing" must be bool, got string; the default is used',
            $started,
            'Initialized telemetry with 0 exporters', // the null exporter entry logs nothing
            $complete,
            $own,
            $started, // the server of the second request
            $complete,
            $own,
            'Rebuilt injection registries on demand (tools/call before tools/list on this instance)',
            'Error: identify callback threw - Illuminate\Contracts\Container\BindingResolutionException: Target class [App\Missing\Identify] does not exist.',
        ], array_map(static fn(string $line): string => str_starts_with($line, $own) ? $own : $line, $logger->messages()));
    }

    /** Review Focus 3: boot, resolve, `tools/call` and flush with `AGENTCAT_ENABLED=maybe`. */
    #[DefineEnvironment('defineAnUnknownEnabledWord')]
    public function testAnUnknownEnabledWordLogsOneLineAndTracksNothing(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $harness = new LaravelHarness(ConformanceServer::class);

        $tools = $harness->listTools()->tools;
        $harness->callTool('echo', ['msg' => 'hi']);
        $this->assertTrue(EventQueue::global()->flush(1.0));

        $this->assertIsArray($tools);
        $this->assertNotSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name')); // the default: off in a test environment
        $this->assertSame(['WARN: AgentCat config "agentcat.enabled" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used'], $logger->messages());
        $this->assertSame([], $this->transport->deliveries);
    }

    /** Octane runs each request in a sandbox container: a hook comes from the container of its request (Review Focus 4). */
    public function testAHookComesFromTheCurrentContainerWhenItRuns(): void
    {
        config(['agentcat.identify' => InvokableIdentify::class]);
        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $application = Container::getInstance();
        $sandbox = new Container();
        $sandbox->instance(InvokableIdentify::class, new InvokableIdentify('from-the-request-container'));

        Container::setInstance($sandbox);
        try {
            $this->assertTrue(EventQueue::global()->flush(1.0));
        } finally {
            Container::setInstance($application);
        }

        $this->assertSame('from-the-request-container', $this->deliveredField('identify_actor_given_id'));
    }

    public function testATrackingFailureLogsOneLineAndTheServerStillAnswers(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $this->app?->bind('log', static fn(): never => throw new \RuntimeException('no log manager'));

        $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertSame('own', JsonAssert::at(end($tools), 'name'));
        $this->assertSame(['Warning: Failed to track server - RuntimeException: no log manager'], $logger->messages());
    }

    public function testTheConfigurationIsPublishedUnderItsTag(): void
    {
        $command = $this->artisan('vendor:publish', ['--tag' => 'agentcat-config']);
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0)->run();

        $this->assertFileEquals(dirname(__DIR__, 3) . '/config/agentcat.php', $this->laravelDirectory . '/config/agentcat.php');
    }

    public function testComposerNamesTheProviderForPackageDiscovery(): void
    {
        $composer = Json::decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'));

        $this->assertSame([AgentCatServiceProvider::class], JsonAssert::at($composer, 'extra', 'laravel', 'providers'));
    }

    /** What the application's default log channel wrote (`LaravelTestCase`); '' before its first line. */
    private function defaultChannelLog(): string
    {
        $path = $this->laravelDirectory . '/laravel.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** A field of the one event the API got. */
    private function deliveredField(string $field): mixed
    {
        $this->assertTrue(EventQueue::global()->flush(1.0));
        $this->assertCount(1, $this->transport->deliveries);
        $delivery = $this->transport->deliveries[0] ?? null;
        $this->assertInstanceOf(Delivery::class, $delivery);

        return JsonAssert::at(Json::decode($delivery->body), $field);
    }

    /**
     * The method map and the raw answer of a new server for `initialize`, `tools/list` and `tools/call`, each request
     * on its own `HttpTransport`, as `Mcp::web()` answers it.
     *
     * @param \Closure(HttpTransport): mixed $build
     *
     * @return list<array{methods: array<mixed>, answer: string}>
     */
    private function answers(\Closure $build): array
    {
        $requests = [
            ['initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'legacy-client', 'version' => '1.0.0']]],
            ['tools/list', new \stdClass()],
            ['tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi'], '_meta' => LaravelHarness::meta([], ['name' => 'modern-client', 'version' => '2.0.0'])]],
        ];
        $answers = [];
        foreach ($requests as $id => [$method, $params]) {
            $body = json_encode(['jsonrpc' => '2.0', 'id' => $id + 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
            $transport = new HttpTransport(Request::create('/mcp/conformance', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost'], content: $body));
            $server = $build($transport);
            $this->assertInstanceOf(ConformanceServer::class, $server);
            $methods = ServerAccess::methods($server);
            $server->start();
            $answers[] = ['methods' => $methods, 'answer' => (string) $transport->run()->getContent()];
        }

        return $answers;
    }
}
