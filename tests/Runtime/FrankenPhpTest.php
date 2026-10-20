<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * FrankenPHP (thread-safe PHP inside a Go server, `max_execution_time` 2), in classic mode and in worker mode (header
 * conflict 12). Classic mode is a per-request SAPI with `frankenphp_finish_request()`: the client has its answer before
 * AgentCat's shutdown function delivers, and the drain gets its own time limit, which under ZTS counts wall time (plan
 * E1 header conflict 14). In worker mode, shutdown functions run only when the worker stops: the raw
 * `frankenphp_handle_request()` loop calls `AgentCat::flush()` after each request, which waits at most 1 s (D58), and
 * the worker's stop delivers the rest. Each upper bound sits about 1 s above the measured time and about 1 s below the
 * time its failure mode takes (ruling P16).
 */
#[Group('runtime')]
final class FrankenPhpTest extends RuntimeTestCase
{
    private const string FIXTURES = '/tests/Runtime/Fixtures/frankenphp';

    private const string CADDYFILE = '/etc/frankenphp/Caddyfile';

    /**
     * How long a classic request may take when nothing of AgentCat's runs before the answer. A request held for the
     * 2 s delivery ends at the 2 s time limit (measured: 2.0 s), so the bound sits 1 s below it.
     */
    private const float ANSWER_SECONDS = 1.0;

    /** The `max_execution_time` of every request: the drain of `slow.php` ends after it. */
    private const float TIME_LIMIT_SECONDS = 2.0;

    /** How long the next request may wait for the loop's flush: the 1 s cap, not the 4 s collector (ruling P16). */
    private const float WORKER_WAIT_SECONDS = RequestEndWait::WORKER_SECONDS + 1.5;

    /** The 2 s window of a process that outlives its requests: its first diagnostics POST comes this late at the earliest. */
    private const float DIAGNOSTICS_WINDOW_SECONDS = 2.0;

    /** How long a test waits for the collector: a delayed delivery, or the 2 s diagnostics window and a request. */
    private const float COLLECTOR_DEADLINE_SECONDS = 10.0;

    /** How often the diagnostics test sends a request while it waits for the POST. */
    private const float REQUEST_INTERVAL_SECONDS = 0.25;

    private ?DockerRuntime $runtime = null;

    #[Group('ac-03-1')]
    #[MaximumDuration(40000)]
    public function testClassicModeAnswersBeforeTheDelivery(): void
    {
        $scenario = self::$collector->scenario([['status' => 202, 'delayMs' => 2000]]);
        $client = $this->warmClassicClient('/classic.php');
        $started = microtime(true);

        $answer = $client->post(self::echoCall(2, 'hi'), self::echoHeaders($scenario));

        $this->assertLessThan(self::ANSWER_SECONDS, $answer['receivedAt'] - $started, 'FrankenPHP held the answer for the delivery');
        $this->assertEchoed('hi', $answer, 2);
        $this->assertSame('frankenphp_finish_request', $answer['headers']['x-agentcat-finish'] ?? null);
        $this->assertSame('0', $answer['headers']['x-agentcat-wait'] ?? null, 'a per-request SAPI leaves the delivery to the shutdown function');
        $this->assertDeliveredAfter($answer, $scenario, self::COLLECTOR_DEADLINE_SECONDS);
    }

    /**
     * Without a Content-Length, the client sees the end of the answer only when FrankenPHP ends the response. Closing
     * and flushing the output buffers (D57) sends the bytes but not the end, so only the shutdown function's call of
     * `frankenphp_finish_request()` gives the client its answer before the delivery.
     */
    #[Group('ac-03-1')]
    #[MaximumDuration(40000)]
    public function testClassicModeEndsAnAnswerWithoutContentLengthBeforeTheDelivery(): void
    {
        $scenario = self::$collector->scenario([['status' => 202, 'delayMs' => 2000]]);
        $client = $this->warmClassicClient('/unsized.php');
        $started = microtime(true);

        $answer = $client->post(self::echoCall(2, 'hi'), self::echoHeaders($scenario));

        $this->assertLessThan(self::ANSWER_SECONDS, $answer['receivedAt'] - $started, 'the shutdown function did not end the answer with frankenphp_finish_request() before the delivery');
        $this->assertSame('removed', $answer['headers']['x-e2e-content-length'] ?? null, 'PHP sent the answer without its Content-Length');
        $this->assertEchoed('hi', $answer, 2);
        $this->assertDeliveredAfter($answer, $scenario, self::COLLECTOR_DEADLINE_SECONDS);
    }

    /**
     * Plan E1 header conflict 14: under ZTS, `max_execution_time` counts wall time, so a request that used most of its
     * 2 s would be killed during the drain. The drain sets its own limit: it waits for the collector past the 2 s,
     * logs the delivery and then posts its diagnostics, the last step of the shutdown. The collector records a request
     * after its delay also when the drain dies, so the diagnostics POST is the proof.
     */
    #[MaximumDuration(40000)]
    public function testTheDrainHasItsOwnTimeLimitUnderZts(): void
    {
        $events = self::$collector->scenario([['status' => 202, 'delayMs' => 2000]]);
        $diagnostics = self::$collector->scenario();
        $client = new McpHttpClient($this->frankenphp(worker: false, env: $this->diagnosticsTo($diagnostics)) . '/slow.php');
        $started = microtime(true);

        // slow.php works 1 s of wall time of its 2 s limit, then answers; the delivery waits 2 s more (ruling P16: about
        // 1 s of slack before the limit, and the drain ends about 1 s after it).
        $answer = $client->post(self::echoCall(1, 'hi'), self::echoHeaders($events));

        $this->assertEchoed('hi', $answer, 1);
        $deliveries = $this->waitForRequests($events, 1, self::COLLECTOR_DEADLINE_SECONDS);
        $this->assertCount(1, $deliveries);
        $this->assertGreaterThan($started + self::TIME_LIMIT_SECONDS, $this->time($deliveries[0], 'end'), 'the collector answered after the 2 s limit');
        $this->waitForSentEventPosts($diagnostics, 1, self::COLLECTOR_DEADLINE_SECONDS);
        $this->assertStringNotContainsString('Maximum execution time', $this->runtime()->logs());
    }

    #[MaximumDuration(40000)]
    public function testWorkerModeIsDetectedAndTheLoopsFlushDeliversEachRequest(): void
    {
        $scenario = self::$collector->scenario();
        $client = new McpHttpClient($this->frankenphp(worker: true) . '/worker.php');

        foreach (['one', 'two'] as $i => $msg) {
            $answer = $client->post(self::echoCall($i + 1, $msg), self::echoHeaders($scenario));
            $this->assertEchoed($msg, $answer, $i + 1);
            $this->assertSame('null', $answer['headers']['x-agentcat-finish'] ?? null, 'no finish function in worker mode');
            $this->assertSame('1', $answer['headers']['x-agentcat-wait'] ?? null, 'D58: a worker waits at most 1 s after each request');
        }

        $this->assertCount(2, $this->waitForRequests($scenario, 2, self::COLLECTOR_DEADLINE_SECONDS)); // the worker is still running
    }

    #[MaximumDuration(60000)]
    public function testWithASlowApiTheWorkerLoopWaitsAtMostOneSecondAndItsStopDelivers(): void
    {
        $scenario = self::$collector->scenario([], ['status' => 202, 'delayMs' => 4000]);
        // one worker thread: a request waits for the previous one's flush; no --rm: the logs are read after the stop (P16)
        $client = new McpHttpClient($this->frankenphp(worker: true, remove: false) . '/worker.php');
        $headers = self::echoHeaders($scenario);
        $this->assertEchoed('m1', $client->post(self::echoCall(1, 'm1'), $headers), 1);
        $started = microtime(true);

        $answer = $client->post(self::echoCall(2, 'm2'), $headers);

        $this->assertLessThan(self::WORKER_WAIT_SECONDS, microtime(true) - $started, 'the loop waited longer than the D58 cap');
        $this->assertEchoed('m2', $answer, 2);
        $runtime = $this->runtime();
        $runtime->stopGracefully(15.0); // frankenphp_handle_request() returns false; the script's shutdown function delivers
        $logs = $runtime->logs();
        $runtime->stop();
        $this->assertStringNotContainsString('Fatal error', $logs);
        $this->assertSame(2, substr_count($this->outFile('agentcat.log'), MockCollector::SENT_EVENT), 'the worker\'s stop waited for both deliveries');
        $this->assertCount(2, $this->waitForRequests($scenario, 2, self::COLLECTOR_DEADLINE_SECONDS));
    }

    /** @return iterable<string, array{array<string, string>, string}> the worker's environment, and its `X-E2E-Server` */
    public static function workerServers(): iterable
    {
        yield 'a server tracked at worker boot' => [['AGENTCAT_E2E_BOOT_SERVER' => '1'], 'boot'];
        yield 'a server tracked in each request' => [[], 'request'];
    }

    /**
     * Plan E2 Task 8 carry: the first `track()` decides the diagnostics window, at worker boot (outside any request:
     * FrankenPHP sets `FRANKENPHP_WORKER` in `$_SERVER` and the environment) or in the first request (only the
     * environment has it). The worker is detected either way, so its queue passes post the lines once they are 2 s
     * old, while the worker still runs. A worker detected as classic would post nothing until it stops.
     *
     * @param array<string, string> $env
     */
    #[DataProvider('workerServers')]
    #[MaximumDuration(40000)]
    public function testAWorkerPostsItsDiagnosticsWhileItRuns(array $env, string $server): void
    {
        $events = self::$collector->scenario();
        $diagnostics = self::$collector->scenario();
        $started = microtime(true);
        $client = new McpHttpClient($this->frankenphp(worker: true, collector: $events, env: [...$env, ...$this->diagnosticsTo($diagnostics)]) . '/worker.php');
        $headers = HttpHarness::modernHeaders('tools/call', 'echo'); // the AgentCat API is the container's AGENTCAT_API_URL

        // Each request runs queue passes (the pump at the handler's entry, then the loop's flush); the first one after
        // the first lines are 2 s old posts them.
        $calls = 0;
        $deadline = microtime(true) + self::COLLECTOR_DEADLINE_SECONDS;
        do {
            ++$calls;
            $answer = $client->post(self::echoCall($calls, 'm' . $calls), $headers);
            $this->assertEchoed('m' . $calls, $answer, $calls);
            $this->assertSame($server, $answer['headers']['x-e2e-server'] ?? null);
            $posts = self::$collector->waitFor($diagnostics, static fn(array $posts): bool => $posts !== [], self::REQUEST_INTERVAL_SECONDS);
        } while ($posts === [] && microtime(true) < $deadline);

        $this->assertNotSame([], $posts, sprintf('No diagnostics POST within %.1f s of requests to the running worker', self::COLLECTOR_DEADLINE_SECONDS));
        $this->assertStringContainsString('AgentCat setup started', MockCollector::body($posts[0]), 'the first POST carries the first track()\'s lines');
        $this->assertGreaterThan($started + self::DIAGNOSTICS_WINDOW_SECONDS, $this->time($posts[0], 'start'), 'the 2 s window, not an earlier post');
        ++$calls;
        $this->assertEchoed('m' . $calls, $client->post(self::echoCall($calls, 'm' . $calls), $headers), $calls); // the worker still runs
        $this->assertCount($calls, $this->waitForRequests($events, $calls, self::COLLECTOR_DEADLINE_SECONDS));
    }

    /**
     * FrankenPHP on 127.0.0.1 only (`bind`, checked: 127.0.0.2 is refused), serving `Fixtures/frankenphp/` in classic
     * mode, or with `worker.php` as a worker of one thread, from a Caddyfile in `out()`. PHP logs its errors (FrankenPHP's image has no php.ini, so `log_errors` is
     * off and a fatal error after the answer would leave no trace). The container gets `RuntimeTestCase::env()` with
     * `$collector` as the AgentCat API, plus `$env`; `HOME` is `/out`, where AgentCat writes its log.
     *
     * @param array<string, string> $env
     * @param bool                  $remove false keeps the stopped container for `logs()` until `stop()`
     *
     * @return string the base URL
     */
    private function frankenphp(bool $worker, ?string $collector = null, array $env = [], bool $remove = true): string
    {
        $out = $this->out();
        $port = $this->freePort();
        $fixtures = self::repo() . self::FIXTURES;
        $caddyfile = $out . '/Caddyfile';
        file_put_contents($caddyfile, implode("\n", [
            '{',
            "\tauto_https off",
            "\tadmin off",
            "\tfrankenphp {",
            "\t\tphp_ini max_execution_time " . (int) self::TIME_LIMIT_SECONDS,
            "\t\tphp_ini log_errors 1", // the image has no php.ini: without it, PHP errors go only to the response
            ...($worker ? ["\t\tworker " . $fixtures . '/worker.php 1'] : []),
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
        $this->runtime = DockerRuntime::start(
            Images::frankenphp(),
            ['frankenphp', 'run', '--config', self::CADDYFILE],
            [...$this->env($collector), ...$env],
            [$caddyfile => self::CADDYFILE, $out => '/out'],
            port: $port,
            remove: $remove,
        );
        $this->assertFalse(DockerRuntime::accepts($port, '127.0.0.2'), 'FrankenPHP listens beyond 127.0.0.1');

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * FrankenPHP in classic mode, after one untimed request to `$script` with its own scenario, whose delivery the
     * collector has received: the timed request then meets a warm OPcache and no drain of the warm-up (ruling P16).
     */
    private function warmClassicClient(string $script): McpHttpClient
    {
        $client = new McpHttpClient($this->frankenphp(worker: false) . $script);
        $this->warmUp($client, self::$collector->scenario(), self::COLLECTOR_DEADLINE_SECONDS);

        return $client;
    }

    private function runtime(): DockerRuntime
    {
        $this->assertInstanceOf(DockerRuntime::class, $this->runtime, 'frankenphp() starts the container');

        return $this->runtime;
    }
}
