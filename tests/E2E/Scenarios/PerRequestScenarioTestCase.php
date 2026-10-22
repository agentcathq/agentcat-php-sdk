<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

use AgentCat\Tests\E2E\Support\BuiltInServer;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The per-request lane of the end-to-end scenarios: `php -S` (`Fixtures/scenario-server.php`), where every HTTP request
 * is a fresh PHP request that builds and tracks its own server, like PHP-FPM, and AgentCat delivers each event at the
 * end of its request. The request headers carry the scenario, the collector scenario and the sink of the test, so one
 * `php -S` serves every test of a class. A subclass picks the era: the handshake (sessions in a FileSessionStore), or
 * 2026-07-28, where every request is self-contained.
 */
abstract class PerRequestScenarioTestCase extends TestCase
{
    use Scenarios;

    private const float COLLECTOR_DEADLINE_SECONDS = 5.0;

    /**
     * How long a test without an event waits before it counts none: a per-request event leaves at the end of its own
     * request, which no later request can wait for. A delivery to the local collector takes milliseconds.
     */
    private const float NO_EVENT_SETTLE_SECONDS = 1.0;

    private static ?MockCollector $collector = null;

    private static ?BuiltInServer $server = null;

    /** HOME (agentcat.log), sessions and sinks of the server, under the test HOME. */
    private static string $dir = '';

    /** The collector scenario of the current test: its AgentCat API. */
    private string $scenario = '';

    /** The sink file name of the current test. */
    private string $sinkId = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $home = getenv('HOME');
        self::assertIsString($home);
        self::$dir = $home . '/e2e-scenarios-' . bin2hex(random_bytes(6));

        try {
            foreach (['', '/home', '/sessions', '/sinks'] as $sub) {
                self::assertTrue(mkdir(self::$dir . $sub, 0o700));
            }
            self::$collector = MockCollector::start();
            self::$server = BuiltInServer::start(__DIR__ . '/../Fixtures/scenario-server.php', [
                'AGENTCAT_ENABLED' => 'true',
                'AGENTCAT_API_URL' => MockCollector::closedPortUrl(), // a request without X-E2E-Collector stays local
                'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(),
                'HOME' => self::$dir . '/home',
                'AGENTCAT_E2E_SESSIONS' => self::$dir . '/sessions',
                'AGENTCAT_E2E_SINKS' => self::$dir . '/sinks',
            ]);
        } catch (\Throwable $e) {
            self::tearDownAfterClass(); // PHPUnit skips it when this method fails

            throw $e;
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        self::$collector?->stop();
        self::$collector = null;
        FileTree::remove(self::$dir);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->scenario = $this->collector()->scenario();
        $this->sinkId = bin2hex(random_bytes(8));
    }

    /** Whether this lane speaks the 2026-07-28 era. */
    abstract protected static function modernEra(): bool;

    /**
     * 21. A `tools/call` before any `tools/list`: the fresh instance rebuilds its registries from the tool listing,
     * so the strip is exact, not the heuristic.
     */
    #[Group('ac-02-53')]
    #[Group('ac-02-20')]
    #[MaximumDuration(10000)]
    public function testRebuildOnDemand(): void
    {
        $client = $this->open(Scenario::RebuildOnDemand);
        $before = $this->rebuildLines();

        $result = $client->call('echo', ['msg' => 'cold start', 'context' => 'rebuild on demand']);

        $sessionId = $this->issuedSessionId($result);
        $this->assertSame('cold start', $this->text($result, 1));
        $event = $this->event();
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
        $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
        $this->assertSame('rebuild on demand', JsonAssert::at($event, 'user_intent'));
        $this->assertSame(['{"msg":"cold start"}'], $this->sink()->seen('echo'));
        $this->assertSame($before + 1, $this->rebuildLines(), 'the call rebuilt the registries');
    }

    /**
     * 21 on page 2 (D66): with mcp/sdk's page size at 1, `loose` is alone on page 2, and the rebuild for a cold call
     * reads the pages up to it. The strip is exact: agent tracking is off, so the `agent_id` the call sends is the
     * customer's and reaches the tool, where the heuristic strip of a tool that no page named would remove it.
     */
    #[Group('ac-02-53')]
    #[Group('ac-02-20')]
    #[MaximumDuration(10000)]
    public function testRebuildOnDemandForAToolOnPage2(): void
    {
        $client = $this->client(Scenario::RebuildOnDemand, ['X-E2E-Page-Size' => '1']);
        $before = $this->rebuildLines();

        $result = $client->call('loose', ['msg' => 'cold start', 'customer_extra' => 'x', 'agent_id' => 'theirs', 'context' => 'page two', 'session_id' => 'start']);

        $this->issuedSessionId($result);
        $this->assertSame(['{"msg":"cold start","customer_extra":"x","agent_id":"theirs"}'], $this->sink()->seen('loose'));
        $event = $this->event();
        $this->assertSame('loose', JsonAssert::at($event, 'resource_name'));
        $this->assertSame('page two', JsonAssert::at($event, 'user_intent'));
        $this->assertSame($before + 1, $this->rebuildLines(), 'the call rebuilt the registries');
    }

    #[\Override]
    protected function open(Scenario $scenario): ScenarioClient
    {
        return $this->client($scenario);
    }

    #[\Override]
    protected function modern(): bool
    {
        return static::modernEra();
    }

    #[\Override]
    protected function deliveries(int $count): array
    {
        return $this->collector()->waitFor($this->scenario, static fn(array $requests): bool => count($requests) >= $count, self::COLLECTOR_DEADLINE_SECONDS);
    }

    #[\Override]
    protected function assertNothingDelivered(ScenarioClient $client): void
    {
        usleep((int) (self::NO_EVENT_SETTLE_SECONDS * 1_000_000));
        $this->assertSame([], $this->collector()->requests($this->scenario), 'no event');
    }

    #[\Override]
    protected function sink(): Sink
    {
        return new Sink(self::$dir . '/sinks/' . $this->sinkId . '.jsonl');
    }

    /**
     * A client of the scenario's server, whose list and call requests carry the test's headers and `$headers`.
     *
     * @param array<string, string> $headers
     */
    private function client(Scenario $scenario, array $headers = []): ScenarioClient
    {
        $this->assertInstanceOf(BuiltInServer::class, self::$server);

        return ScenarioClient::connect(self::$server->baseUrl() . '/', static::modernEra(), [
            'X-E2E-Scenario' => $scenario->value,
            'X-E2E-Collector' => $this->scenario,
            'X-E2E-Sink' => $this->sinkId,
            ...$headers,
        ]);
    }

    /** How often the server's agentcat.log says that a request rebuilt the registries. */
    private function rebuildLines(): int
    {
        $file = self::$dir . '/home/agentcat.log';
        $log = is_file($file) ? file_get_contents($file) : ''; // a 2026-07-28 test can come first: no request logged yet
        $this->assertIsString($log);

        return substr_count($log, self::REBUILT);
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
