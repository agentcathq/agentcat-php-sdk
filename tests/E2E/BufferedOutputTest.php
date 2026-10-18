<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

use AgentCat\Tests\E2E\Support\BuiltInServer;
use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `php -S` with php.ini-production's `output_buffering=4096` (D57): a SAPI without a finish-request function, whose
 * response waits in PHP's output buffer until the end of the request unless AgentCat sends it before the delivery.
 */
final class BufferedOutputTest extends TestCase
{
    /** The collector answers the event after this long: an answer that waited for the delivery comes at least that late. */
    private const int SLOW_COLLECTOR_MS = 2000;

    private const float COLLECTOR_DEADLINE_SECONDS = 6.0;

    private static ?MockCollector $collector = null;

    private static ?BuiltInServer $server = null;

    /** HOME (agentcat.log) of the server, under the test HOME (Ruling R8). */
    private static string $dir = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $home = getenv('HOME');
        self::assertIsString($home);
        self::$dir = $home . '/e2e-buffered-' . bin2hex(random_bytes(6));

        try {
            self::assertTrue(mkdir(self::$dir, 0o700));
            self::$collector = MockCollector::start();
            self::$server = BuiltInServer::start(__DIR__ . '/Fixtures/buffered-server.php', [
                'AGENTCAT_ENABLED' => 'true',
                'AGENTCAT_API_URL' => MockCollector::closedPortUrl(), // a request without X-E2E-Collector stays local
                'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(),
                'HOME' => self::$dir,
            ], ['output_buffering' => '4096']);
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

    #[Group('ac-03-1')]
    #[Group('ac-02-48')]
    #[MaximumDuration(10000)]
    public function testABufferedAnswerReachesTheClientBeforeTheDelivery(): void
    {
        $collector = $this->collector();
        $scenario = $collector->scenario([['status' => 202, 'delayMs' => self::SLOW_COLLECTOR_MS]]);
        $client = new McpHttpClient($this->server()->baseUrl() . '/mcp');
        $started = microtime(true);

        $answer = $client->callToolModern('echo', ['msg' => 'hi', 'session_id' => 'start', 'context' => 'buffered'], ['name' => 'c', 'version' => '1'], ['X-E2E-Collector' => $scenario]);

        $this->assertLessThan(1.5, $answer['receivedAt'] - $started, 'the answer waited for the delivery');
        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($answer['message']), 'content', 1, 'text'));
        $deadline = microtime(true) + self::COLLECTOR_DEADLINE_SECONDS;
        while ($collector->requests($scenario) === [] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertCount(1, $collector->requests($scenario), 'the delivery ran after the answer');
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }

    private function server(): BuiltInServer
    {
        $this->assertInstanceOf(BuiltInServer::class, self::$server);

        return self::$server;
    }
}
