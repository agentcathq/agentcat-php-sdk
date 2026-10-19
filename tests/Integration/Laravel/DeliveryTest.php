<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Laravel\Internal\AfterRequest;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\Laravel\ContainerRequestIdentity;
use AgentCat\Tests\Support\Laravel\HeaderIdentity;
use AgentCat\Tests\Support\Laravel\InjectedRequestIdentity;
use AgentCat\Tests\Support\LaravelMcp\HeaderIdentityServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Laravel\Mcp\Facades\Mcp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Delivery after each request of a Laravel application whose process outlives the request, as in an Octane worker
 * (plan E1 header conflict 13, D58, Review Focus 4). Each request goes through Laravel's HTTP kernel to `Mcp::web()`,
 * and Laravel's test client runs `$kernel->terminate()` after it, as a server does after it sent the response.
 */
final class DeliveryTest extends BridgeTestCase
{
    private const string OCTANE_WORKER_STOPPING = 'Laravel\Octane\Events\WorkerStopping';

    private static ?MockCollector $collector = null;

    private ?Environment $console = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
        parent::tearDownAfterClass();
    }

    /**
     * Laravel reads APP_RUNNING_IN_CONSOLE once, when it builds the application, so the value comes first: Octane's,
     * whose worker bootstrap puts the bool false into `$_ENV`.
     */
    #[\Override]
    protected function setUp(): void
    {
        $this->console = Environment::clear('APP_RUNNING_IN_CONSOLE');
        $this->setRunningInConsole(false);
        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->console?->restore();
    }

    /**
     * AgentCat on, with a project. A test that forgets its collector fails closed: the API URL is a closed local port.
     *
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);
        config(['agentcat.enabled' => true, 'agentcat.project_id' => LaravelTrackingFixture::PROJECT, 'agentcat.api_url' => MockCollector::closedPortUrl()]);
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes(mixed $router): void
    {
        Mcp::web('/mcp/test', HeaderIdentityServer::class);
    }

    public function testTheApplicationRunsOutsideTheConsole(): void
    {
        $this->assertFalse($this->application()->runningInConsole());
    }

    #[Group('ac-03-12')]
    #[Group('ac-02-48')]
    public function testTheTerminatingCallbackDeliversTheRequestsEvents(): void
    {
        $response = $this->postJson('/mcp/test', $this->toolCall('echo', ['msg' => 'hi', 'session_id' => 'start']), $this->modernHeaders('echo'));

        $response->assertOk();
        $this->assertCount(1, $this->transport->deliveries); // Laravel's test client runs $kernel->terminate()
    }

    public function testOctanesWorkerStopEndsTheQueue(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $this->application()->make('events')->dispatch(self::OCTANE_WORKER_STOPPING);
        EventQueue::global()->add(new QueuedEvent(new Event(projectId: LaravelTrackingFixture::PROJECT)));

        $this->assertContains('Queue is shutting down, event dropped', $logger->messages());
    }

    /**
     * A failing hook never stops Laravel's terminate phase (D32): the hook asks the application it got whether it runs
     * in the console, that call throws, the exception stays in the hook, and the callbacks after it still run.
     */
    public function testAFailingHookLetsTheLaterTerminatingCallbacksRun(): void
    {
        $application = Container::getInstance();
        try {
            $broken = new class extends Application {
                public bool $failing = false;

                public int $thrown = 0;

                #[\Override]
                public function runningInConsole(): bool
                {
                    if (!$this->failing) {
                        return parent::runningInConsole(); // the constructor asks too
                    }
                    ++$this->thrown;

                    throw new \RuntimeException('no environment');
                }
            };
        } finally {
            Container::setInstance($application); // a new application makes itself the global container
        }
        $broken->failing = true;
        $later = new \ArrayObject();
        AfterRequest::register($broken);
        $broken->terminating(static function () use ($later): void {
            $later->append('ran');
        });
        $reached = null;

        try {
            $broken->terminate();
        } catch (\Throwable $e) {
            $reached = $e;
        }

        $this->assertSame(1, $broken->thrown); // the hook's call threw ...
        $this->assertNull($reached); // ... and the exception did not reach Laravel's terminate()
        $this->assertSame(['ran'], $later->getArrayCopy());
    }

    /**
     * APP_RUNNING_IN_CONSOLE and the hook's wait. Laravel's `runningInConsole()` returns the value as `Env::get()` gives
     * it, a string for "0", "1" or "no", and Laravel branches on its truthiness: so does the hook.
     *
     * @return iterable<string, array{string|false|null, float}>
     */
    public static function consoleValues(): iterable
    {
        yield 'Octane: the bool false' => [false, RequestEndWait::WORKER_SECONDS];
        yield '"0": not the console for Laravel' => ['0', RequestEndWait::WORKER_SECONDS];
        yield '"1": a console command' => ['1', 0.0];
        yield '"no": the console for Laravel, a non-empty string' => ['no', 0.0];
        yield 'unset: the CLI SAPI makes it a console command' => [null, 0.0];
    }

    /**
     * Outside the console the hook waits for the cap; in a console command it makes one pass that starts the delivery
     * and does not wait, because the process ends soon and its shutdown function delivers (D47). Each case builds a
     * new Laravel application, which reads the variable as it starts.
     */
    #[DataProvider('consoleValues')]
    public function testOnlyAnApplicationOutsideTheConsoleWaits(string|false|null $value, float $wait): void
    {
        $this->setRunningInConsole($value);
        $application = Container::getInstance();
        try {
            $started = new Application();
        } finally {
            Container::setInstance($application); // a new application makes itself the global container
        }
        AfterRequest::register($started);
        $this->transport->complete = false; // an API that does not answer; the clock is simulated
        EventQueue::global()->add(new QueuedEvent(new Event(projectId: LaravelTrackingFixture::PROJECT)));
        $before = $this->transport->now();

        $started->terminate();

        $this->assertCount(1, $this->transport->deliveries, 'the hook starts the delivery with APP_RUNNING_IN_CONSOLE ' . var_export($value, true));
        $this->assertSame($wait, $this->transport->now() - $before);
    }

    /** A request that queued no event leaves the hook nothing to wait for, also in a worker and with a silent API. */
    public function testARequestThatQueuedNoEventDoesNotWait(): void
    {
        $this->transport->complete = false;
        $before = $this->transport->now();
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => LaravelHarness::meta([], ['name' => 'modern-client', 'version' => '2.0.0'])]];

        $tools = $this->postJson('/mcp/test', $body, ['MCP-Protocol-Version' => LaravelHarness::MODERN_PROTOCOL_VERSION, 'Mcp-Method' => 'tools/list'])->assertOk()->json('result.tools');

        $this->assertIsArray($tools);
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name')); // a tracked server; a listing queues no event
        $this->assertSame([], $this->transport->deliveries);
        $this->assertSame(0, $this->transport->waits);
        $this->assertSame($before, $this->transport->now());
    }

    /** Review Focus 4: three requests in one application container, each with its own server, headers and identity. */
    public function testRequestsInOneContainerKeepTheirOwnContext(): void
    {
        config(['agentcat.identify' => HeaderIdentity::class]);

        foreach (['ann', 'bob', 'cid'] as $user) {
            $this->postJson('/mcp/test', $this->toolCall('echo', ['msg' => $user, 'session_id' => 'start']), [...$this->modernHeaders('echo'), 'X-User' => $user])->assertOk();
        }

        $bodies = array_map(static fn(Delivery $d): mixed => Json::decode($d->body), $this->transport->deliveries);
        $this->assertSame(['ann', 'bob', 'cid'], array_map(static fn(mixed $body): mixed => JsonAssert::at($body, 'identify_actor_given_id'), $bodies));
        $this->assertSame(['ann', 'bob', 'cid'], array_map(static fn(mixed $body): mixed => JsonAssert::at($body, 'parameters', 'extra', 'requestInfo', 'headers', 'x-user'), $bodies));
    }

    /**
     * `identify` hooks that read the container, as Laravel code reads the authenticated user.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function containerHooks(): iterable
    {
        yield 'the container\'s current request' => [ContainerRequestIdentity::class];
        yield 'a request injected into the constructor' => [InjectedRequestIdentity::class];
    }

    /**
     * Review Focus 4 with a hook that reads the container and an AgentCat API that backs up. The first five deliveries
     * stay in flight, so the sixth event waits for a free slot past its own request. Its hook still runs before that
     * request's wait returns, in that request: the payload alone waits for the transport.
     *
     * @param class-string $hook
     */
    #[DataProvider('containerHooks')]
    #[Group('ac-03-1')]
    #[MaximumDuration(2000)]
    public function testAContainerReadingHookRunsInItsOwnRequestWhileTheApiIsBackedUp(string $hook): void
    {
        config(['agentcat.identify' => $hook]);
        $this->transport->complete = false; // a slow AgentCat API: the deliveries stay in flight; the clock is simulated
        $users = ['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7'];

        foreach ($users as $user) {
            $this->transport->complete = $user === 'u7'; // the API answers again before the seventh request
            $this->postJson('/mcp/test', $this->toolCall('echo', ['msg' => $user, 'session_id' => 'start']), [...$this->modernHeaders('echo'), 'X-User' => $user])->assertOk();
        }

        $bodies = array_map(static fn(Delivery $d): mixed => Json::decode($d->body), $this->transport->deliveries);
        $this->assertSame($users, array_map(static fn(mixed $body): mixed => JsonAssert::at($body, 'parameters', 'request', 'params', 'arguments', 'msg'), $bodies));
        $this->assertSame($users, array_map(static fn(mixed $body): mixed => JsonAssert::at($body, 'identify_actor_given_id'), $bodies));
    }

    /** Review Focus 4: an AgentCat API that does not answer holds each request for the cap, not for the 5 s budget. */
    #[Group('ac-03-1')]
    public function testAWorkerWaitsAtMostTheCapAndTheNextRequestDeliversTheRest(): void
    {
        $this->transport->complete = false; // an API that does not answer within the request; the clock is simulated
        $before = $this->transport->now();

        $this->postJson('/mcp/test', $this->toolCall('echo', ['msg' => 'one', 'session_id' => 'start']), $this->modernHeaders('echo'))->assertOk();

        $this->assertSame(RequestEndWait::WORKER_SECONDS, $this->transport->now() - $before); // the cap (D58), not the 5 s budget
        $this->assertSame(1, $this->transport->inFlight());
        $this->transport->complete = true;

        $this->postJson('/mcp/test', $this->toolCall('echo', ['msg' => 'two', 'session_id' => 'start']), $this->modernHeaders('echo'))->assertOk();

        $this->assertSame(0, $this->transport->inFlight()); // the second request's pump() finished the first delivery
        $this->assertCount(2, $this->transport->deliveries);
    }

    /**
     * Review Focus 4 with the real curl transport. The collector answers after 4 s: a hook that waited for the 5 s
     * shutdown budget would hold the request at least that long, and the worker's stop still has time to deliver.
     */
    #[MaximumDuration(12000)]
    public function testWithASlowApiTheHookReturnsAfterAboutOneSecondAndTheWorkerStopDelivers(): void
    {
        $collector = self::$collector;
        $this->assertInstanceOf(MockCollector::class, $collector);
        $scenario = $collector->scenario([['status' => 202, 'delayMs' => 4000]]);
        EventQueue::setGlobal(new EventQueue()); // the real curl transport, and no shutdown function in the test process
        config(['agentcat.api_url' => $scenario]);
        $started = microtime(true);

        $this->postJson('/mcp/test', $this->toolCall('echo', ['msg' => 'hi', 'session_id' => 'start']), $this->modernHeaders('echo'))->assertOk();

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(RequestEndWait::WORKER_SECONDS - 0.1, $elapsed); // it waited for the slow API ...
        $this->assertLessThan(RequestEndWait::WORKER_SECONDS + 1.5, $elapsed); // ... for the cap, not for the 5 s shutdown budget
        $this->assertSame([], $collector->requests($scenario)); // the collector records a request when it answers
        $this->application()->make('events')->dispatch(self::OCTANE_WORKER_STOPPING);

        $this->assertCount(1, $collector->requests($scenario)); // shutdown() waited for the answer inside its 5 s
    }

    /**
     * A JSON-RPC `tools/call` of the 2026-07-28 era (laravel/mcp's default) with the client info in `_meta`.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function toolCall(string $tool, array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments, '_meta' => LaravelHarness::meta([], ['name' => 'modern-client', 'version' => '2.0.0'])]];
    }

    /**
     * The headers that laravel/mcp's `ValidateMcpHeaders` requires of a 2026-07-28 `tools/call`.
     *
     * @return array<string, string>
     */
    private function modernHeaders(string $tool): array
    {
        return ['MCP-Protocol-Version' => LaravelHarness::MODERN_PROTOCOL_VERSION, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool];
    }

    /**
     * Sets APP_RUNNING_IN_CONSOLE: the bool false in `$_ENV` as Octane does, a string in the process environment, or
     * null for unset. `setUp()` saved the variable, and `tearDown()` puts it back.
     */
    private function setRunningInConsole(string|false|null $value): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');
        unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);
        if ($value === false) {
            $_ENV['APP_RUNNING_IN_CONSOLE'] = false;
        } elseif (is_string($value)) {
            putenv('APP_RUNNING_IN_CONSOLE=' . $value);
            $_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = $value;
        }
    }

    private function application(): Application
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return $this->app;
    }
}
