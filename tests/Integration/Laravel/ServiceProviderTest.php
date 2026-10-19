<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Laravel\AgentCatServiceProvider;
use AgentCat\Laravel\Internal\ServerTracker;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\Laravel\InvokableIdentify;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\LaravelMcp\PagedServer;
use AgentCat\Tests\Support\MemoryLogger;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Testing\PendingCommand;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServiceProviderTest extends BridgeTestCase
{
    private const string OTHER_PROJECT = 'proj_ppppppppppppppppppppppppppp';

    /** True when a `DefineEnvironment` method of the test sets the whole configuration (it can run first). */
    private bool $ownConfiguration = false;

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
            'agentcat.enable_tracing' => 'yes', // a D44 word since M3: tracing stays on, no line
            'agentcat.enable_report_missing' => 'maybe',
            'agentcat.identify' => 'App\Missing\Identify',
            'agentcat.exporters' => ['otlp' => null],
            'agentcat.unknown_key' => 1,
        ]);
    }

    /** Review Focus 3 for the bridge's own keys: each of a wrong type, before the providers boot. */
    public function defineBadBridgeKeys(): void
    {
        $this->ownConfiguration = true;
        config([
            'agentcat.enabled' => true,
            'agentcat.project_id' => LaravelTrackingFixture::PROJECT,
            'agentcat.exclude' => 42,
            'agentcat.projects' => [ConformanceServer::class => 7, PagedServer::class => self::OTHER_PROJECT],
            'agentcat.log_channel' => ['single'],
            'agentcat.diagnostics_endpoint' => 5,
            'agentcat.diagnostics_token' => false,
        ]);
    }

    public function defineANonStringProjectId(): void
    {
        $this->ownConfiguration = true;
        config(['agentcat.enabled' => true, 'agentcat.project_id' => 12345]);
        putenv('AGENTCAT_PROJECT_ID=' . self::OTHER_PROJECT); // BridgeTestCase puts the variable back after the test
    }

    public function defineAgentCatOff(): void
    {
        $this->ownConfiguration = true;
        config(['agentcat.enabled' => false, 'agentcat.project_id' => LaravelTrackingFixture::PROJECT]);
    }

    public function defineEnabledUnset(): void
    {
        $this->ownConfiguration = true;
        config(['agentcat.enabled' => null, 'agentcat.project_id' => LaravelTrackingFixture::PROJECT]);
    }

    /** Review Focus 3: `AGENTCAT_ENABLED=maybe`, which `env()` gives the configuration too, before the providers boot. */
    public function defineAnUnknownEnabledWord(): void
    {
        $this->ownConfiguration = true;
        putenv('AGENTCAT_ENABLED=maybe');
        config(['agentcat.enabled' => 'maybe', 'agentcat.project_id' => LaravelTrackingFixture::PROJECT]);
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

    /** M2: a string is a list of one class, so the server the customer meant to exclude is not tracked. */
    public function testAStringExcludeIsAListOfOneClass(): void
    {
        config(['agentcat.exclude' => ConformanceServer::class]);

        $this->assertUntouched();
    }

    /** M2: an entry that is no class name logs one line; the other entries still exclude, and a null one is unset. */
    public function testAnExcludeEntryOfAnotherTypeIsIgnoredWithOneLine(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        config(['agentcat.exclude' => [null, 7, ConformanceServer::class]]);

        $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;
        $harness = new LaravelHarness(PagedServer::class);
        $harness->listTools();
        $harness->listTools();

        $this->assertIsArray($tools);
        $this->assertNotSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
        $this->assertSame(['WARN: AgentCat option "agentcat.exclude.1" must be string, got int; the entry is ignored'], array_values(array_filter($logger->messages(), static fn(string $line): bool => str_starts_with($line, 'WARN:'))));
    }

    /** M2: the bridge's keys of a wrong type log one line each, once, and their defaults are used. */
    #[DefineEnvironment('defineBadBridgeKeys')]
    public function testBridgeKeysOfAWrongTypeLogOneLineEachAndFallBack(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        (new LaravelHarness(PagedServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame([
            'WARN: AgentCat option "agentcat.exclude" must be array or string, got int; the default is used',
            'WARN: AgentCat option "agentcat.log_channel" must be string or null, got array; the default is used',
            'WARN: AgentCat option "agentcat.projects.' . ConformanceServer::class . '" must be string or null, got int; the entry is ignored',
            'WARN: AgentCat option "agentcat.diagnostics_endpoint" must be string or null, got int; the default is used',
            'WARN: AgentCat option "agentcat.diagnostics_token" must be string or null, got bool; the default is used',
        ], array_values(array_filter($logger->messages(), static fn(string $line): bool => str_starts_with($line, 'WARN:'))));
        $events = $this->deliveries();
        $this->assertSame([LaravelTrackingFixture::PROJECT, LaravelTrackingFixture::PROJECT, self::OTHER_PROJECT], array_map(static fn(mixed $event): mixed => JsonAssert::at($event, 'project_id'), $events));
    }

    /** M2: `projects` that is not a map logs one line, and every server goes to `project_id`. */
    public function testProjectsOfAnotherTypeLogOneLineAndTheProjectIdIsUsed(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        config(['agentcat.projects' => self::OTHER_PROJECT]);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(LaravelTrackingFixture::PROJECT, $this->deliveredField('project_id'));
        $this->assertContains('WARN: AgentCat option "agentcat.projects" must be array or null, got string; the default is used', $logger->messages());
    }

    /** M2: a `project_id` that is not a string logs one line, and AGENTCAT_PROJECT_ID of the process decides. */
    #[DefineEnvironment('defineANonStringProjectId')]
    public function testAProjectIdOfAnotherTypeLogsOneLineAndTheVariableDecides(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(self::OTHER_PROJECT, $this->deliveredField('project_id'));
        $this->assertContains('WARN: AgentCat option "agentcat.project_id" must be string or null, got int; the default is used', $logger->messages());
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

    /** D53: two server classes of one application send to two projects. */
    public function testTwoServerClassesSendToTwoProjects(): void
    {
        config(['agentcat.projects' => [PagedServer::class => self::OTHER_PROJECT]]);

        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        (new LaravelHarness(PagedServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $events = $this->deliveries();
        $this->assertCount(2, $events);
        $this->assertSame([[LaravelTrackingFixture::PROJECT, 'conformance-server'], [self::OTHER_PROJECT, 'paged-server']], array_map(static fn(mixed $event): array => [JsonAssert::at($event, 'project_id'), JsonAssert::at($event, 'server_name')], $events));
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

    /** D60: without a channel name, AgentCat's lines go to its own file, `storage/logs/agentcat.log`, not to the application's log. */
    #[DataProvider('defaultChannels')]
    public function testWithoutALogChannelNameTheLinesGoToStorageLogsAgentCatLog(mixed $channel): void
    {
        config(['agentcat.log_channel' => $channel]);

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', $this->agentCatLog());
        $this->assertStringNotContainsString('AgentCat', $this->defaultChannelLog());
    }

    /** D60: `log_channel` routes the lines into any channel of the application, its default one too. */
    public function testALogChannelNameRoutesTheLinesIntoTheApplicationsChannel(): void
    {
        config(['agentcat.log_channel' => 'single']);

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', $this->defaultChannelLog());
        $this->assertSame('', $this->agentCatLog());
    }

    /**
     * I3: laravel/mcp builds a server per HTTP request, so the setup lines, and an ERROR line for a tool that declares
     * its own `session_id`, repeat per request. They stay out of the application's log and its error trackers.
     */
    public function testRequestsWriteNoAgentCatEntryIntoTheApplicationsLog(): void
    {
        $harness = new LaravelHarness(ConformanceServer::class);

        for ($i = 0; $i < 3; ++$i) {
            $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        }
        $harness->listTools();

        $this->assertSame('', $this->defaultChannelLog());
        $this->assertStringContainsString('agentcat.ERROR: ERROR: Tool "own" already declares', $this->agentCatLog());
        $this->assertSame(4, substr_count($this->agentCatLog(), 'AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT));
    }

    /** Laravel does not keep the emergency logger of a channel it cannot create: AgentCat asks for it once. */
    public function testAMistypedLogChannelGivesOneEmergencyEntry(): void
    {
        config(['agentcat.log_channel' => 'agentcat-mistyped']);
        $harness = new LaravelHarness(ConformanceServer::class);

        $harness->listTools();
        $harness->listTools();
        $harness->listTools();

        $emergency = $this->laravelDirectory . '/storage/logs/laravel.log';
        $this->assertFileExists($emergency);
        $this->assertSame(1, substr_count((string) file_get_contents($emergency), 'Unable to create configured logger. Using emergency logger.'));
    }

    public function testAfterAFakeTheNextServerRoutesTheLinesToTheChannel(): void
    {
        AgentCat::fake();
        (new LaravelHarness(ConformanceServer::class))->listTools();
        AgentCat::setClient(null);

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', $this->agentCatLog());
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

    public function testAnExcludedServerKeepsItsMethodsAndItsAnswers(): void
    {
        config(['agentcat.exclude' => [ConformanceServer::class]]);

        $this->assertUntouched();
    }

    #[DefineEnvironment('defineAgentCatOff')]
    public function testWithAgentCatOffAServerKeepsItsMethodsAndItsAnswers(): void
    {
        $this->assertUntouched();
    }

    /** D31: `agentcat.enabled` unset in a test environment. */
    #[DefineEnvironment('defineEnabledUnset')]
    public function testWithoutASwitchInATestAServerKeepsItsMethodsAndItsAnswers(): void
    {
        $this->assertUntouched();
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
        $nowhere = 'Warning: No projectId provided and no exporters configured. Events will not be sent anywhere.'; // only null exporter entries
        $complete = 'AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=0';
        $own = 'ERROR: Tool "own" already declares a \'session_id\' parameter.'; // the fixture's own tool, for every server
        $this->assertSame([
            'WARN: AgentCat config "agentcat.enable_report_missing" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used',
            'WARN: Unknown AgentCat option "agentcat.unknown_key" ignored', // once: the options serve every server
            $started,
            $nowhere,
            $complete,
            $own,
            $started, // the server of the second request
            $nowhere,
            $complete,
            $own,
            'Rebuilt injection registries on demand (tools/call before tools/list on this instance)',
            'Error: identify callback threw - Illuminate\Contracts\Container\BindingResolutionException: Target class [App\Missing\Identify] does not exist.',
        ], array_map(static fn(string $line): string => str_starts_with($line, $own) ? $own : $line, $logger->messages()));
    }

    /** M3: `'enable_tracing' => env('AGENTCAT_ENABLE_TRACING')` with `AGENTCAT_ENABLE_TRACING=0` turns tracing off. */
    public function testAZeroFromEnvTurnsTracingOff(): void
    {
        config(['agentcat.enable_tracing' => '0']);

        $answer = (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame('echo: hi', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertSame([], $this->deliveries());
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
        // No logger is chosen, so the tracker asks Laravel for its channel, and the line goes to agentcat.log in HOME:
        // a HOME of this test, which the test removes.
        $home = Environment::clear('HOME');
        putenv('HOME=' . $this->laravelDirectory);
        try {
            $this->app?->bind('log', static fn(): never => throw new \RuntimeException('no log manager'));

            $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;
            Log::reset(); // closes the file logger of this HOME
        } finally {
            $home->restore();
        }

        $this->assertIsArray($tools);
        $this->assertSame('own', JsonAssert::at(end($tools), 'name'));
        $lines = file($this->laravelDirectory . '/agentcat.log', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $this->assertStringEndsWith(' Warning: Failed to track server - RuntimeException: no log manager', $lines[0]);
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

    /**
     * An untracked server keeps laravel/mcp's own method classes and answers exactly like a server that the container
     * never built (no `afterResolving` callback, so no AgentCat at all). Nothing is logged or queued.
     */
    private function assertUntouched(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $this->assertSame($this->answers(static fn(HttpTransport $transport): Server => new ConformanceServer($transport)), $this->answers(static fn(HttpTransport $transport): mixed => Container::getInstance()->make(ConformanceServer::class, ['transport' => $transport])));
        $this->assertSame([], $logger->messages());
        $this->assertFalse(EventQueue::global()->hasWork());
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
