<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Tests\Integration\Support\MockCollector;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** I2: signal handlers inside the side channel's curl callbacks (`signal-in-callback.php`, `exit-in-callback.php`). */
final class SignalInCallbackTest extends TestCase
{
    private const int ROUNDS = 20;

    private static ?MockCollector $collector = null;

    /** HOME of the fixture (its agentcat.log), under the test HOME. */
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
        $this->dir = getenv('HOME') . '/signal-in-callback-' . bin2hex(random_bytes(6));
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

    /**
     * A drain inside a write callback, then a returning earlier handler, many times over: the process does not crash,
     * every side request is answered without an error, and the drain delivered the event on the transport's handle.
     * Measured locally: about 0.3 s, with about 100 of 900 signals inside curl_multi_exec().
     */
    #[MaximumDuration(10000)]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testADrainInsideACurlCallbackDoesNotCrashTheProcess(): void
    {
        $events = $this->collector()->scenario();
        $side = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 4_000_000)]);
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/signal-in-callback.php', $events, $side, (string) self::ROUNDS],
            null,
            ['HOME' => $this->dir, 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl()],
        );
        $process->setTimeout(20);

        try {
            $process->run();
        } catch (ProcessSignaledException $e) {
            self::fail('the fixture crashed: signal ' . $e->getSignal());
        }

        $this->assertFalse($process->hasBeenSignaled());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(1, preg_match('/\Asurvived signals=(\d+) nested=(\d+) done=(\d+) errors=(\d+)\n\z/', $process->getOutput(), $match), $process->getOutput());
        $this->assertGreaterThan(0, (int) $match[2], 'some drains ran inside curl_multi_exec()');
        $this->assertGreaterThanOrEqual((int) $match[2], (int) $match[1], 'each drain called the earlier handler');
        $this->assertSame(self::ROUNDS, (int) $match[3], 'every side request was answered');
        $this->assertSame(0, (int) $match[4], 'without an error');
        $this->assertCount(1, $this->collector()->requests($events), 'the drain delivered the event');
    }

    /**
     * A handler that calls exit() inside a write callback: the callback ends before it clears the channel's busy flag.
     * The channel sees that no curl_multi_exec() runs any more, so the shutdown function can still send on it.
     */
    #[MaximumDuration(10000)]
    public function testAnExitInsideACurlCallbackLeavesTheChannelUsableAtShutdown(): void
    {
        $side = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 200_000)]);
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/exit-in-callback.php', $side],
            null,
            ['HOME' => $this->dir, 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl()],
        );
        $process->setTimeout(10);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("exit\nafter: 200 -\npending: 0\n", $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
