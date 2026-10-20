<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Apache with mod_php (prefork) and `output_buffering=4096`, as in php.ini-production. mod_php has no finish-request
 * function, so a client knows that it has the whole answer only from its Content-Length, and AgentCat's last shutdown
 * pass sends PHP's output buffers before the hooks and the delivery (D57). The answer of an mcp/sdk server that sets
 * its own Content-Length, of a symfony/mcp-bundle server (D62: `AgentCatBundle::boot()` registers the listener that
 * sets it) and of a laravel/mcp server (D59) then reaches the client before the delivery. Each upper bound sits about
 * 1 s above the measured time and about 1 s below the time a held answer takes, the collector's 2 s (ruling P16).
 */
#[Group('runtime')]
final class ApacheTest extends RuntimeTestCase
{
    /** PHP's errors go to Apache's error log, the container's stderr: the image has no php.ini, and without it a warning would reach only the answer. */
    private const string INI = "output_buffering=4096\nlog_errors=1";

    /** How long the timed request may take when nothing of AgentCat's runs before the answer. */
    private const float ANSWER_SECONDS = 1.0;

    /** How long the test waits for the collector: a delayed delivery, then the diagnostics post. */
    private const float COLLECTOR_DEADLINE_SECONDS = 15.0;

    /** @return iterable<string, array{string}> the MCP endpoint under `tests/`, Apache's document root */
    public static function servers(): iterable
    {
        yield 'mcp/sdk, with its own Content-Length' => ['/E2E/Fixtures/buffered-server.php'];
        yield 'symfony/mcp-bundle (D62)' => ['/Runtime/Fixtures/symfony-front.php/mcp/conformance'];
        yield 'laravel/mcp (D59)' => ['/Runtime/Fixtures/laravel-front.php/mcp/e2e'];
    }

    /**
     * An untimed first request compiles the Symfony container and fills Laravel's caches, and its delivery takes the
     * collector's undelayed step; the timed request's delivery waits 2 s. The diagnostics posts prove that each
     * request's drain logged its delivery: the collector records a request also when the drain dies.
     */
    #[DataProvider('servers')]
    #[Group('ac-03-1')]
    #[MaximumDuration(60000)]
    public function testModPhpWithOutputBufferingAnswersBeforeTheDelivery(string $path): void
    {
        $events = self::$collector->scenario([['status' => 202], ['status' => 202, 'delayMs' => 2000]]);
        $diagnostics = self::$collector->scenario();
        [$runtime, $base] = $this->apache($events, $diagnostics);
        $client = new McpHttpClient($base . $path);
        $this->assertEchoed('warm', $client->post(self::echoCall(1, 'warm'), self::echoHeaders($events)), 1);
        $this->waitForRequests($events, 1, self::COLLECTOR_DEADLINE_SECONDS);
        $started = microtime(true);

        $answer = $client->post(self::echoCall(2, 'hi'), self::echoHeaders($events));

        $this->assertLessThan(self::ANSWER_SECONDS, $answer['receivedAt'] - $started, 'mod_php held the answer for the delivery: its buffers were not sent (D57), or it had no Content-Length (D59, D62)');
        $this->assertEchoed('hi', $answer, 2);
        $this->assertSame((string) strlen($answer['body']), $answer['headers']['content-length'] ?? null, 'the answer carries its length');
        $deliveries = $this->waitForRequests($events, 2, self::COLLECTOR_DEADLINE_SECONDS);
        $this->assertCount(2, $deliveries);
        $this->assertGreaterThan($answer['receivedAt'] + 1.0, $this->time($deliveries[1], 'end'), 'the collector answered the delivery after the client had its answer');
        $this->waitUntil($diagnostics, static fn(array $posts): bool => count(MockCollector::withASentEvent($posts)) >= 2, self::COLLECTOR_DEADLINE_SECONDS, 'each request logged its delivery and posted its diagnostics');
        $this->assertDoesNotMatchRegularExpression('/PHP (Fatal error|Warning)/', $runtime->logs());
    }

    /**
     * Apache on 127.0.0.1 only (checked: 127.0.0.2 is refused), serving `tests/` with mod_php, from three files in
     * `out()`: `ports.conf`, the default site and a php.ini snippet. The container gets `RuntimeTestCase::env()` with
     * `$events` as the AgentCat API, and diagnostics on and sent to `$diagnostics`; `PassEnv` hands every one of these
     * names to PHP. `HOME` is `/out`, where AgentCat writes its log.
     *
     * @return array{DockerRuntime, string} the container, and its base URL
     */
    private function apache(string $events, string $diagnostics): array
    {
        $out = $this->out();
        $port = $this->freePort();
        $root = self::repo() . '/tests';
        $env = [...$this->env($events), ...$this->diagnosticsTo($diagnostics)];
        $files = [
            'ports.conf' => 'Listen 127.0.0.1:' . $port,
            'site.conf' => implode("\n", [
                '<VirtualHost 127.0.0.1:' . $port . '>',
                "\tDocumentRoot " . $root,
                "\t<Directory " . $root . '>',
                "\t\tRequire all granted",
                "\t</Directory>",
                "\tPassEnv " . implode(' ', array_keys($env)),
                '</VirtualHost>',
            ]),
            'php.ini' => self::INI,
        ];
        foreach ($files as $name => $contents) {
            $this->assertNotFalse(file_put_contents($out . '/' . $name, $contents . "\n"));
        }
        $runtime = DockerRuntime::start(Images::APACHE, ['apache2-foreground'], $env, [
            $out . '/ports.conf' => '/etc/apache2/ports.conf',
            $out . '/site.conf' => '/etc/apache2/sites-enabled/000-default.conf',
            $out . '/php.ini' => '/usr/local/etc/php/conf.d/zz-agentcat.ini',
            $out => '/out',
        ], port: $port);
        $this->assertFalse(DockerRuntime::accepts($port, '127.0.0.2'), 'Apache listens beyond 127.0.0.1');

        return [$runtime, 'http://127.0.0.1:' . $port];
    }
}
