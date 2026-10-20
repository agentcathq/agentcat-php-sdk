<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** A CLI server that gets SIGTERM with an event in the global queue (`signal-drain.php`). */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class SignalDrainDeliveryTest extends TestCase
{
    /** Seconds from start to death by SIGTERM; the fixture's own loop runs for 5 s. */
    private const float SIGNAL_DEADLINE = 3.0;

    /** `EventQueue::SHUTDOWN_TIMEOUT_SECONDS`. */
    private const float BUDGET = 5.0;

    private static ?MockCollector $collector = null;

    /** HOME of the script (its agentcat.log) and the marker file. */
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
        $this->dir = sys_get_temp_dir() . '/agentcat-signal-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->dir, 0o700));
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $names = scandir($this->dir);
        foreach ($names === false ? [] : $names as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        rmdir($this->dir);
    }

    #[Group('ac-03-10')]
    #[MaximumDuration(10000)]
    public function testWithoutAHandlerTheQueueDrainsThenSigtermEndsTheProcess(): void
    {
        $base = $this->collector()->scenario();
        $process = $this->fixture($base, 'default');

        $signaled = null;
        $started = microtime(true);

        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            $signaled = $e;
        }
        $elapsed = microtime(true) - $started;

        $this->assertInstanceOf(ProcessSignaledException::class, $signaled, sprintf('exit code %s: %s', var_export($process->getExitCode(), true), $process->getErrorOutput()));
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertSame(15, $process->getTermSignal());
        $this->assertLessThan(self::SIGNAL_DEADLINE, $elapsed);
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(['signal-drain'], $this->resourceNames($base));
    }

    #[MaximumDuration(10000)]
    public function testWithTheHandlersDisabledSigtermEndsTheProcessAtOnceAndNothingIsSent(): void
    {
        $base = $this->collector()->scenario();
        $process = $this->fixture($base, 'disabled');

        $signaled = null;
        $started = microtime(true);

        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            $signaled = $e;
        }
        $elapsed = microtime(true) - $started;

        $this->assertInstanceOf(ProcessSignaledException::class, $signaled, sprintf('exit code %s: %s', var_export($process->getExitCode(), true), $process->getErrorOutput()));
        $this->assertSame(15, $process->getTermSignal());
        $this->assertLessThan(self::SIGNAL_DEADLINE, $elapsed);
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame([], $this->resourceNames($base));
    }

    /** A host can disable `posix_getpid()`: the drain then ends the process with the shell's status for SIGTERM. */
    #[MaximumDuration(10000)]
    public function testWithoutPosixGetpidTheDrainEndsTheProcessWithTheExitStatusOfTheSignal(): void
    {
        $base = $this->collector()->scenario();
        $process = $this->fixture($base, 'outside', '', ['-d', 'disable_functions=posix_getpid']);

        $process->start();
        $ready = microtime(true) + self::SIGNAL_DEADLINE;
        while ($process->isRunning() && $process->getOutput() === '' && microtime(true) < $ready) {
            usleep(10_000);
        }
        $this->assertSame("ready\n", $process->getOutput(), $process->getErrorOutput());
        $process->signal(15);
        $process->wait();

        $this->assertFalse($process->hasBeenSignaled());
        $this->assertSame(128 + 15, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(['signal-drain'], $this->resourceNames($base));
    }

    #[MaximumDuration(10000)]
    public function testWithTheHandlersDisabledANormalExitStillDeliversThroughTheShutdownFunction(): void
    {
        $base = $this->collector()->scenario();
        $process = $this->fixture($base, 'disabled-shutdown');

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("ending\n", $process->getOutput());
        $this->assertSame(['signal-drain'], $this->resourceNames($base));
    }

    #[Group('ac-03-11')]
    #[MaximumDuration(10000)]
    public function testWithACustomerHandlerTheQueueDrainsThenTheHandlerRunsAndTheProcessGoesOn(): void
    {
        $base = $this->collector()->scenario();
        $marker = $this->dir . '/marker';
        $process = $this->fixture($base, 'chain', $marker);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("done\n", $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(['signal-drain'], $this->resourceNames($base));
        $this->assertFileExists($marker, 'the customer handler ran');
        $contents = file_get_contents($marker);
        $this->assertIsString($contents);
        $lines = explode("\n", $contents);
        $this->assertCount(3, $lines);
        [$who, $time, $signo] = $lines;
        $this->assertSame('customer', $who);
        $this->assertIsNumeric($time);
        $this->assertSame('15', $signo, "the handler typed (int \$signo, array \$info) got PHP's siginfo");
        $requests = $this->collector()->requests($base);
        $this->assertCount(1, $requests);
        $start = $requests[0]->start;
        $this->assertIsNumeric($start);
        $this->assertGreaterThan((float) $start, (float) $time, 'the queue drained before the customer handler ran');
    }

    /** TypeScript `process.once`: AgentCat gives SIGTERM back before it drains, so a second one ends the process. */
    #[MaximumDuration(10000)]
    public function testASecondSigtermDuringTheDrainEndsTheProcessAtOnce(): void
    {
        [$hole, $url] = $this->blackHole();
        $process = $this->fixture($url, 'twice');
        $process->start();
        $this->assertTrue($this->waitForLine($process, 'signaled'), $process->getErrorOutput());
        $first = microtime(true);
        $this->waitUntil($process, $first + 0.3);

        $process->signal(15);
        $process->wait();
        $elapsed = microtime(true) - $first;

        $this->assertTrue($process->hasBeenSignaled(), $process->getErrorOutput());
        $this->assertSame(15, $process->getTermSignal());
        $this->assertLessThan(self::SIGNAL_DEADLINE - 1.0, $elapsed, 'the second SIGTERM did not wait for the 5 s drain');
        fclose($hole);
    }

    /** One budget: a chained handler that exits runs the shutdown function, which must not drain for 5 s more. */
    #[Group('ac-03-11')]
    #[MaximumDuration(10000)]
    public function testAChainedHandlerThatExitsEndsWithinOneShutdownBudget(): void
    {
        [$hole, $url] = $this->blackHole();
        $process = $this->fixture($url, 'chain-exit');
        $process->start();
        $this->assertTrue($this->waitForLine($process, 'signaled'), $process->getErrorOutput());
        $signaled = microtime(true);

        $process->wait();
        $elapsed = microtime(true) - $signaled;

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertGreaterThan(self::BUDGET - 0.5, $elapsed, 'the drain used its budget (the collector never answers)');
        $this->assertLessThan(self::BUDGET + 0.5, $elapsed, 'one 5 s budget, not two');
        fclose($hole);
    }

    /** One budget: SIGTERM 2 s into the shutdown function's flush ends the process when that flush would have. */
    #[Group('ac-03-10')]
    #[MaximumDuration(10000)]
    public function testASigtermDuringTheShutdownFlushKeepsItsDeadline(): void
    {
        [$hole, $url] = $this->blackHole();
        $process = $this->fixture($url, 'shutdown');
        $process->start();
        $this->assertTrue($this->waitForLine($process, 'ending'), $process->getErrorOutput());
        $flushStart = microtime(true);
        $this->waitUntil($process, $flushStart + 2.0);

        $process->signal(15);
        $process->wait();
        $elapsed = microtime(true) - $flushStart;

        $this->assertTrue($process->hasBeenSignaled(), $process->getErrorOutput());
        $this->assertSame(15, $process->getTermSignal());
        $this->assertGreaterThan(self::BUDGET - 0.5, $elapsed, 'the signal drain kept the remaining budget');
        $this->assertLessThan(self::BUDGET + 0.5, $elapsed, 'the signal did not start a new 5 s budget');
        fclose($hole);
    }

    /** @param list<string> $php options of the PHP binary, before the script */
    private function fixture(string $base, string $mode, string $marker = '', array $php = []): Process
    {
        $process = new Process(
            [PHP_BINARY, ...$php, __DIR__ . '/../Fixtures/signal-drain.php', $base, $mode, $marker],
            null,
            ['HOME' => $this->dir],
        );
        $process->setTimeout(10);

        return $process;
    }

    /**
     * A listening socket that never accepts: the kernel completes the connection, curl sends its request, and no answer
     * comes (a collector that is reachable but stuck). Keep the socket open while the fixture runs.
     *
     * @return array{resource, string} the socket and its base URL
     */
    private function blackHole(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, $errorMessage ?? '');
        $name = stream_socket_get_name($socket, false);
        $this->assertIsString($name);

        return [$socket, 'http://' . $name];
    }

    /** Waits (within the process timeout) until the fixture prints `$line`; false when it ended first. */
    private function waitForLine(Process $process, string $line): bool
    {
        $output = '';

        return $process->waitUntil(static function (string $type, string $chunk) use (&$output, $line): bool {
            $output .= $chunk;

            return str_contains($output, $line . "\n");
        });
    }

    /** Waits until `$deadline` (microtime) while the fixture runs. */
    private function waitUntil(Process $process, float $deadline): void
    {
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(5_000);
        }
        $this->assertTrue($process->isRunning(), 'the fixture ended early: ' . $process->getErrorOutput());
    }

    /** @return list<mixed> the `resource_name` of each request the collector got, in order */
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
