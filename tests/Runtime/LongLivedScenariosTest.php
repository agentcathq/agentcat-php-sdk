<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Internal\Constants;
use AgentCat\Tests\E2E\Scenarios\Scenario;
use AgentCat\Tests\E2E\Scenarios\ScenarioClient;
use AgentCat\Tests\E2E\Scenarios\Scenarios;
use AgentCat\Tests\E2E\Scenarios\Sink;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;

/**
 * The long-lived lane of the end-to-end scenarios: a FrankenPHP worker (`Fixtures/frankenphp/scenarios.php`, one
 * thread) whose one server, tracked and built at worker boot, serves the whole MCP session over HTTP with its sessions
 * in memory, as a long-lived PHP process does. Each test starts its own container, for the options of its scenario.
 */
#[Group('runtime')]
final class LongLivedScenariosTest extends RuntimeTestCase
{
    use Scenarios;

    private const string CADDYFILE = '/etc/frankenphp/Caddyfile';

    /** The sink file, in the output directory (`/out` in the container). */
    private const string SINK = 'sink.jsonl';

    /** How long a test waits for the collector: the loop's flush delivers each event before the next request. */
    private const float COLLECTOR_DEADLINE_SECONDS = 5.0;

    /** The collector scenario of the current test: the AgentCat API of its container. */
    private string $scenario = '';

    /**
     * 22. The session of the transport stays one across the calls, and the AgentCat session that the first call mints
     * and the second supplies is the same on both events; the transport's session never becomes it. The registries
     * that the listing built serve both calls: no call rebuilds them, as one long-lived instance serves them all.
     */
    #[Group('ac-02-53')]
    #[Group('ac-02-46')]
    #[MaximumDuration(20000)]
    public function testSessionContinuityStateful(): void
    {
        $client = $this->open(Scenario::SessionContinuityStateful);
        $httpSessionId = $client->sessionId();
        $this->assertIsString($httpSessionId);
        $this->assertNotSame('', $httpSessionId);
        $client->listTools();
        $sessionId = $this->issuedSessionId($client->call('echo', ['msg' => 'one', 'context' => 'session continuity 1', 'session_id' => 'start']));

        $second = $client->call('echo', ['msg' => 'two', 'context' => 'session continuity 2', 'session_id' => $sessionId]);

        $this->assertNull($this->mintBack($second));
        $this->assertSame($httpSessionId, $client->sessionId(), 'one Mcp-Session-Id for every request');
        [$one, $two] = $this->twoEvents();
        foreach ([$one, $two] as $event) {
            $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
            $this->assertSame($httpSessionId, JsonAssert::at($event, 'parameters', 'extra', 'sessionId'), 'the transport session stays in parameters.extra');
        }
        $this->assertSame('minted', $this->tag($one, Constants::TAG_SESSION_SOURCE));
        $this->assertSame('supplied', $this->tag($two, Constants::TAG_SESSION_SOURCE));
        $this->assertStringNotContainsString(self::REBUILT, $this->outFile('agentcat.log'), 'one instance listed and served the calls');
    }

    /**
     * FrankenPHP on 127.0.0.1 only, with `scenarios.php` as a worker of one thread, from a Caddyfile in `out()`. The
     * container gets `RuntimeTestCase::env()` with this test's collector scenario as the AgentCat API, the scenario
     * and the sink file; `HOME` is `/out`, where AgentCat writes its log.
     */
    #[\Override]
    protected function open(Scenario $scenario): ScenarioClient
    {
        $this->scenario = self::$collector->scenario();
        $out = $this->out();
        $port = $this->freePort();
        $fixtures = self::repo() . '/tests/Runtime/Fixtures/frankenphp';
        $caddyfile = $out . '/Caddyfile';
        file_put_contents($caddyfile, implode("\n", [
            '{',
            "\tauto_https off",
            "\tadmin off",
            "\tfrankenphp {",
            "\t\tphp_ini log_errors 1", // the image has no php.ini: without it, PHP errors go only to the response
            "\t\tworker " . $fixtures . '/scenarios.php 1',
            "\t}",
            '}',
            '',
            'http://127.0.0.1:' . $port . ' {',
            "\tbind 127.0.0.1", // the site address alone names a host to match, and Caddy would listen on every interface
            "\troot * " . $fixtures,
            "\tphp_server",
            '}',
            '',
        ]));
        DockerRuntime::start(
            Images::frankenphp(),
            ['frankenphp', 'run', '--config', self::CADDYFILE],
            [...$this->env($this->scenario), 'AGENTCAT_E2E_SCENARIO' => $scenario->value, 'AGENTCAT_E2E_SINK' => '/out/' . self::SINK],
            [$caddyfile => self::CADDYFILE, $out => '/out'],
            port: $port,
        );
        $this->assertFalse(DockerRuntime::accepts($port, '127.0.0.2'), 'FrankenPHP listens beyond 127.0.0.1');

        return ScenarioClient::connect('http://127.0.0.1:' . $port . '/scenarios.php', false);
    }

    #[\Override]
    protected function modern(): bool
    {
        return false;
    }

    #[\Override]
    protected function deliveries(int $count): array
    {
        return self::$collector->waitFor($this->scenario, static fn(array $requests): bool => count($requests) >= $count, self::COLLECTOR_DEADLINE_SECONDS);
    }

    /**
     * The worker serves one request at a time and flushes AgentCat's queue after each one, so once a later request has
     * its answer, every event of the earlier ones has reached the collector.
     */
    #[\Override]
    protected function assertNothingDelivered(ScenarioClient $client): void
    {
        $client->listTools();
        $this->assertSame([], self::$collector->requests($this->scenario), 'no event');
    }

    #[\Override]
    protected function sink(): Sink
    {
        return new Sink($this->out() . '/' . self::SINK);
    }
}
