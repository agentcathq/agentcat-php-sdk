<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * N1: PHP runs a SIGTERM that arrived during curl_multi_exec() right after that call returns (`signal-after-exec.php`).
 * The drain runs there, with the default disposition after it, and must still deliver.
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class SignalAfterExecTest extends TestCase
{
    /** `EventQueue::SHUTDOWN_TIMEOUT_SECONDS` is 5 s: a drain that cannot deliver spends all of it. */
    private const float LIFETIME_AFTER_SIGTERM = 3.0;

    private static ?MockCollector $collector = null;

    /** HOME of the fixture, under the test HOME. */
    private string $dir = '';

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

    #[\Override]
    protected function setUp(): void
    {
        $this->dir = getenv('HOME') . '/signal-after-exec-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->dir, 0o700));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $names = scandir($this->dir);
        foreach ($names === false ? [] : $names as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function callers(): iterable
    {
        yield 'the event transport was in curl_multi_exec()' => ['transport'];
        yield 'the side channel was in curl_multi_exec()' => ['channel'];
    }

    /** Measured locally: the process ends about 0.01-0.1 s after SIGTERM; before the fix it lived 5 s and lost the event. */
    #[DataProvider('callers')]
    #[MaximumDuration(15000)]
    public function testADrainRightAfterCurlMultiExecDeliversTheEventAndItsExport(string $mode): void
    {
        $large = $this->collector()->scenario([], ['status' => 202, 'body' => str_repeat('x', 8_000_000)]);
        $events = $this->collector()->scenario();
        $exports = $this->collector()->scenario();
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/signal-after-exec.php', $mode, $large, $events, $exports],
            null,
            ['HOME' => $this->dir, 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl()],
        );
        $process->setTimeout(40);

        try {
            $process->run();
        } catch (ProcessSignaledException) {
            // expected: SIGTERM with the default disposition ends the fixture after the drain
        }
        $ended = microtime(true);

        $this->assertSame('', $process->getOutput(), 'the fixture ran out of rounds before the probe found the moment');
        $this->assertTrue($process->hasBeenSignaled(), $process->getErrorOutput());
        $this->assertSame(15, $process->getTermSignal());
        $this->assertSame(1, preg_match('/^sigterm (\d+\.\d+)$/m', $process->getErrorOutput(), $match), $process->getErrorOutput());
        $this->assertLessThan(self::LIFETIME_AFTER_SIGTERM, $ended - (float) $match[1], 'the drain did not spend its 5 s budget waiting');
        $this->assertContains('drain', $this->resourceNames($events), 'the queued event went out on the event transport');
        $this->assertContains('/drain', array_map(static fn(\stdClass $request): mixed => $request->path, $this->collector()->requests($exports)), 'and its export on the side channel');
    }

    /**
     * I1: an application's own handler that throws right after curl_multi_exec() returns (five times per process). On
     * PHP 8.3 this corrupted the frame while the exec call was nested in another call's arguments: before the fix the
     * fixture crashed in most runs (see the report). Now the server goes on, delivers every event and exits 0.
     */
    #[MaximumDuration(15000)]
    public function testAHandlerThatThrowsRightAfterCurlMultiExecLeavesTheServerRunning(): void
    {
        $large = $this->collector()->scenario([], ['status' => 202, 'body' => str_repeat('x', 4_000_000)]);
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/throw-after-exec.php', $large, '5'],
            null,
            ['HOME' => $this->dir, 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl()],
        );
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            self::fail('the fixture crashed: signal ' . $e->getSignal() . ' ' . $process->getErrorOutput());
        }

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(1, preg_match('/\Athrows=(\d+) left=0\n\z/', $process->getOutput(), $match), $process->getOutput());
        $throws = (int) $match[1];
        $this->assertGreaterThan(0, $throws, 'the probe found the moment');
        $names = $this->resourceNames($large);
        for ($i = 1; $i <= $throws; ++$i) {
            $this->assertContains('thrown-' . $i, $names, 'the event queued by the throwing handler went out');
        }
    }

    /** @return list<mixed> the `resource_name` of each event the scenario received */
    private function resourceNames(string $base): array
    {
        $names = [];
        foreach ($this->collector()->requests($base) as $request) {
            $body = $request->body;
            $this->assertIsString($body);
            $names[] = JsonAssert::at(Json::decode($body), 'resource_name');
        }

        return $names;
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
