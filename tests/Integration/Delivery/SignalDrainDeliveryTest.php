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
        $this->assertCount(2, $lines);
        [$who, $time] = $lines;
        $this->assertSame('customer', $who);
        $this->assertIsNumeric($time);
        $requests = $this->collector()->requests($base);
        $this->assertCount(1, $requests);
        $start = $requests[0]->start;
        $this->assertIsNumeric($start);
        $this->assertGreaterThan((float) $start, (float) $time, 'the queue drained before the customer handler ran');
    }

    private function fixture(string $base, string $mode, string $marker = ''): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/signal-drain.php', $base, $mode, $marker],
            null,
            ['HOME' => $this->dir],
        );
        $process->setTimeout(10);

        return $process;
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
