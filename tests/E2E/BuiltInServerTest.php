<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

use AgentCat\Tests\E2E\Support\BuiltInServer;
use AgentCat\Tests\E2E\Support\McpHttpClient;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** No server process outlives its test: neither a hung `php -S` worker nor a server whose test process hangs. */
final class BuiltInServerTest extends TestCase
{
    private const float DEADLINE_SECONDS = 5.0;

    /** Markers of the hung server, under the test HOME. */
    private string $dir = '';

    #[\Override]
    protected function setUp(): void
    {
        $home = getenv('HOME');
        $this->assertIsString($home);
        $this->dir = $home . '/e2e-server-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->dir, 0o700));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_file($this->dir . '/pid')) {
            unlink($this->dir . '/pid');
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    #[MaximumDuration(10000)]
    public function testStopEndsAWorkerThatIgnoresSigterm(): void
    {
        $server = BuiltInServer::start(__DIR__ . '/Fixtures/hung-server.php', ['AGENTCAT_E2E_MARKERS' => $this->dir]);
        $response = (new McpHttpClient($server->baseUrl() . '/'))->post(['jsonrpc' => '2.0', 'method' => 'ping']);
        $this->assertSame('hanging', $response['body']);
        $pid = file_get_contents($this->dir . '/pid');
        $this->assertIsString($pid);
        $this->assertIsNumeric($pid);
        $this->assertTrue($this->alive((int) $pid), 'the worker hangs after its response');

        $server->stop();

        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        while ($this->alive((int) $pid) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->assertFalse($this->alive((int) $pid), 'stop() ended the hung worker');
    }

    #[MaximumDuration(10000)]
    public function testTheWatchdogStopsItsCommandAfterTheTimeout(): void
    {
        $watchdog = new Process(
            [PHP_BINARY, __DIR__ . '/../Integration/Support/watchdog.php', PHP_BINARY, '-r', 'sleep(30);'],
            null,
            ['AGENTCAT_WATCHDOG_TIMEOUT' => '0.2'],
            new InputStream(), // stays open: only the timeout can stop the command
            10,
        );
        $startedAt = microtime(true);

        $watchdog->start();
        $deadline = $startedAt + self::DEADLINE_SECONDS;
        while ($watchdog->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFalse($watchdog->isRunning(), 'the watchdog ended after its timeout');
        $this->assertSame(0, $watchdog->getExitCode());
    }

    /**
     * A zombie (exited, not yet reaped by its new parent) counts as ended.
     *
     * @phpstan-impure
     */
    private function alive(int $pid): bool
    {
        if (!posix_kill($pid, 0)) {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');

        return !is_string($stat) || preg_match('/\) Z /', $stat) !== 1;
    }
}
