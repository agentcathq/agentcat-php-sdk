<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NGINX Unit 1.34.2 with PHP 8.3.26. Unit's PHP module reports the SAPI of `php -S` (`cli-server`), runs
 * each request as a PHP request of its own, and has `fastcgi_finish_request()`, which ends the answer also when it
 * carries no Content-Length. Each upper bound sits about 1 s above the measured time and about 1 s below the time a
 * held answer takes, the collector's 2 s.
 */
#[Group('runtime')]
final class UnitTest extends RuntimeTestCase
{
    /** Unit's state directory: a `conf.json` there is the configuration Unit starts with. */
    private const string STATE_DIRECTORY = '/var/lib/unit';

    /** How long the timed request may take when nothing of AgentCat's runs before the answer. */
    private const float ANSWER_SECONDS = 1.0;

    /** How long the test waits for the collector: a delayed delivery, then the diagnostics post. */
    private const float COLLECTOR_DEADLINE_SECONDS = 15.0;

    /** @return iterable<string, array{array<string, string>, bool}> the headers that pick the answer, and whether it carries its length */
    public static function answers(): iterable
    {
        yield 'with Content-Length' => [[], true];
        yield 'without Content-Length' => [['X-E2E-Unsized' => '1'], false];
    }

    /**
     * An untimed first request warms up the application processes, with a scenario of its own whose delivery the
     * collector has received. The diagnostics posts prove that each request's drain logged its delivery, and Unit's log
     * shows that PHP logged no error, warning, notice or deprecation.
     *
     * @param array<string, string> $headers
     */
    #[DataProvider('answers')]
    #[Group('ac-03-1')]
    #[MaximumDuration(60000)]
    public function testTheAnswerReachesTheClientBeforeTheDelivery(array $headers, bool $sized): void
    {
        $events = self::$collector->scenario([['status' => 202, 'delayMs' => 2000]]);
        $diagnostics = self::$collector->scenario();
        [$runtime, $base] = $this->unit($diagnostics, null, 1);
        $client = new McpHttpClient($base . '/mcp');
        $this->warmUp($client, self::$collector->scenario(), self::COLLECTOR_DEADLINE_SECONDS, $headers);
        $started = microtime(true);

        $answer = $client->post(self::echoCall(2, 'hi'), [...self::echoHeaders($events), ...$headers]);

        $this->assertLessThan(self::ANSWER_SECONDS, $answer['receivedAt'] - $started, 'Unit held the answer for the delivery');
        $this->assertEchoed('hi', $answer, 2);
        $this->assertSame($sized, isset($answer['headers']['content-length']), 'the answer carries its length, or not');
        $this->assertSame('cli-server', $answer['headers']['x-e2e-sapi'] ?? null, 'Unit reports the SAPI of php -S');
        $this->assertSame('fastcgi_finish_request', $answer['headers']['x-agentcat-finish'] ?? null);
        $this->assertSame('0', $answer['headers']['x-agentcat-wait'] ?? null, 'a per-request SAPI leaves the delivery to the shutdown function');
        $this->assertDeliveredAfter($answer, $events, self::COLLECTOR_DEADLINE_SECONDS);
        $this->waitForSentEventPosts($diagnostics, 2, self::COLLECTOR_DEADLINE_SECONDS);
        $this->assertNoPhpErrors($runtime);
    }

    /** @return iterable<string, array{string}> the MCP endpoint that `Fixtures/unit.php` hands to a bridge's front controller */
    public static function bridges(): iterable
    {
        yield 'symfony/mcp-bundle' => ['/mcp/conformance'];
        yield 'laravel/mcp' => ['/mcp/e2e'];
    }

    /**
     * The bridges under Unit: the answer carries no Content-Length (`fastcgi_finish_request()` ends it, so neither
     * bridge adds one) and still reaches the client before the delayed delivery. An untimed first request compiles
     * the Symfony container and fills Laravel's caches, and its delivery takes the collector's undelayed step; the
     * timed request's delivery waits 2 s.
     */
    #[DataProvider('bridges')]
    #[Group('ac-03-1')]
    #[MaximumDuration(60000)]
    public function testTheBridgesAnswerBeforeTheDelivery(string $path): void
    {
        $events = self::$collector->scenario([['status' => 202], ['status' => 202, 'delayMs' => 2000]]);
        $diagnostics = self::$collector->scenario();
        [$runtime, $base] = $this->unit($diagnostics, $events, 1);
        $client = new McpHttpClient($base . $path);
        $this->warmUp($client, $events, self::COLLECTOR_DEADLINE_SECONDS);
        $started = microtime(true);

        $answer = $client->post(self::echoCall(2, 'hi'), self::echoHeaders($events));

        $this->assertLessThan(self::ANSWER_SECONDS, $answer['receivedAt'] - $started, 'Unit held the answer for the delivery');
        $this->assertEchoed('hi', $answer, 2);
        $this->assertArrayNotHasKey('content-length', $answer['headers'], 'the bridge added a length that Unit does not need');
        $this->assertSame('cli-server', $answer['headers']['x-e2e-sapi'] ?? null, 'Unit reports the SAPI of php -S');
        $this->assertDeliveredAfter($answer, $events, self::COLLECTOR_DEADLINE_SECONDS, 2);
        $this->waitForSentEventPosts($diagnostics, 2, self::COLLECTOR_DEADLINE_SECONDS);
        $this->assertNoPhpErrors($runtime);
    }

    /**
     * NGINX Unit on 127.0.0.1 only (checked: 127.0.0.2 is refused), running `Fixtures/unit.php` for every request in
     * `$processes` application processes. Unit cannot route a request to a chosen process, so a case with one warm-up
     * request uses one process: the timed request never meets a cold one. The configuration is `conf.json` in a state
     * directory in `out()`, so Unit starts with it once: the image's entrypoint configures an empty state directory
     * with a Unit that it stops again, and the port would accept connections before that restart.
     *
     * The application gets `RuntimeTestCase::env()` with diagnostics on and sent to `$diagnostics`. Its AgentCat API is
     * `$events` for a bridge's fixture, which reads it from the environment, and a closed port otherwise (the echo
     * fixtures read the request's `X-E2E-Collector`). PHP's errors go to Unit's log, the container's output. `HOME` is
     * `/out`, where AgentCat writes its log.
     *
     * @return array{DockerRuntime, string} the container, and its base URL
     */
    private function unit(string $diagnostics, ?string $events, int $processes): array
    {
        $out = $this->out();
        $port = $this->freePort();
        $env = [...$this->env($events), ...$this->diagnosticsTo($diagnostics)];
        $state = $out . '/unit';
        $this->assertTrue(mkdir($state, 0o777) && chmod($state, 0o777), 'Cannot create ' . $state);
        $this->assertNotFalse(file_put_contents($state . '/conf.json', json_encode([
            'listeners' => ['127.0.0.1:' . $port => ['pass' => 'applications/agentcat']],
            'applications' => ['agentcat' => [
                'type' => 'php',
                'processes' => $processes,
                'root' => self::repo() . '/tests/Runtime/Fixtures',
                'script' => 'unit.php',
                'environment' => $env,
                'options' => ['admin' => ['log_errors' => '1']],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        $runtime = DockerRuntime::start(Images::unit(), ['unitd', '--no-daemon', '--control', 'unix:/var/run/control.unit.sock'], $env, [$state => self::STATE_DIRECTORY, $out => '/out'], port: $port);
        $this->assertFalse(DockerRuntime::accepts($port, '127.0.0.2'), 'Unit listens beyond 127.0.0.1');

        return [$runtime, 'http://127.0.0.1:' . $port];
    }
}
