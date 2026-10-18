<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Laravel\Internal\AfterRequest;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\Laravel\HeaderIdentity;
use AgentCat\Tests\Support\LaravelMcp\HeaderIdentityServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Laravel\Mcp\Facades\Mcp;
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
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
    }

    /** Octane's worker bootstrap sets APP_RUNNING_IN_CONSOLE=false before it creates the application. */
    #[\Override]
    protected function setUp(): void
    {
        $this->console = Environment::clear('APP_RUNNING_IN_CONSOLE');
        putenv('APP_RUNNING_IN_CONSOLE=false');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
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

    /** A failing hook never stops Laravel's terminate phase: the callbacks after it still run (D32). */
    public function testAFailingHookLetsTheLaterTerminatingCallbacksRun(): void
    {
        $application = Container::getInstance();
        try {
            $broken = new class extends Application {
                public bool $failing = false;

                #[\Override]
                public function runningInConsole(): bool
                {
                    return $this->failing ? throw new \RuntimeException('no environment') : parent::runningInConsole();
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

        $broken->terminate();

        $this->assertSame(['ran'], $later->getArrayCopy());
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

    private function application(): Application
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return $this->app;
    }
}
