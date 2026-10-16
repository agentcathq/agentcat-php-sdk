<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\JsonAssert;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ShutdownDeliveryTest extends TestCase
{
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
        $this->dir = sys_get_temp_dir() . '/agentcat-shutdown-' . bin2hex(random_bytes(6));
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

    #[Group('ac-03-12')]
    #[MaximumDuration(10000)]
    public function testQueuedEventIsDeliveredAfterTheCustomersShutdownFunctions(): void
    {
        $base = $this->collector()->scenario();
        $marker = $this->dir . '/marker';
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../Fixtures/shutdown-delivery.php', $base, $marker],
            null,
            ['HOME' => $this->dir],
        );
        $process->setTimeout(10);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("done\n", $process->getOutput());
        $requests = $this->collector()->requests($base);
        $this->assertCount(1, $requests);
        $body = $requests[0]->body;
        $this->assertIsString($body);
        $this->assertSame('shutdown-delivery', JsonAssert::at(Json::decode($body), 'resource_name'));
        $markerTime = file_get_contents($marker);
        $this->assertIsString($markerTime);
        $this->assertIsNumeric($markerTime);
        $start = $requests[0]->start;
        $this->assertIsNumeric($start);
        $this->assertGreaterThan((float) $markerTime, (float) $start, 'the queue flushed after the customer shutdown function');
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
