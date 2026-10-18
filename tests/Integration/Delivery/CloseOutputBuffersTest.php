<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Internal\Json\Json;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The shutdown pass keeps no level: `RequestEnd::closeOutputBuffers()` sends and closes PHP's own buffer
 * (`output_buffering`) too. Inside PHPUnit the bottom buffer is PHPUnit's, and PHPUnit 13 reports a test that closes
 * it, so a PHP process with `output_buffering` on runs the close.
 */
final class CloseOutputBuffersTest extends TestCase
{
    #[MaximumDuration(5000)]
    public function testTheDefaultCloseSendsEveryRemovableBufferPhpsOwnIncluded(): void
    {
        $process = new Process([PHP_BINARY, '-d', 'output_buffering=4096', __DIR__ . '/../Fixtures/close-output-buffers.php']);
        $process->setTimeout(10);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('held after the close', $process->getOutput(), 'the close sent the buffered text');
        $this->assertEquals(
            (object) ['before' => ['default output handler', 'default output handler'], 'after' => 0],
            Json::decode($process->getErrorOutput()),
            "PHP's own buffer and the script's were open, and none is left",
        );
    }
}
