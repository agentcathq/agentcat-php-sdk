<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\ServerInfo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The output guard of the heartbeat build in a real process, with real output buffers that a test process could never
 * get rid of. The fixture is a worker that writes its responses with `echo`: after a heartbeat run whose list handler
 * left a bad buffer open, the later responses must arrive whole.
 */
final class OutputGuardProcessTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function badBuffers(): iterable
    {
        // The customer's own text, which waited in the buffer that PHP could not remove, arrives late, and whole.
        yield 'a buffer that PHP cannot remove' => ['stuck', '[left in the bad buffer]', ''];
        yield 'a buffer that PHP cannot remove and that the worker flushes after each response' => ['flushable', '[left in the bad buffer]', "bytes that waited below the bad buffer after a flush: 0\n"];
        // PHP removes this buffer although its handler throws, and its content with it.
        yield 'a buffer whose handler throws on the clean call' => ['throwing', '', ''];
    }

    #[DataProvider('badBuffers')]
    public function testAfterARunWithABadBufferTheLaterResponsesOfAWorkerArriveWholeAndTheErrorHandlerIsBack(string $mode, string $late, string $held): void
    {
        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'output_buffering=0', __DIR__ . '/../Fixtures/server-info-output.php', $mode], null, null, null, 20.0);

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
        $expected = "response 0\n"
            . $late
            . sprintf("response 1: %s\n", str_repeat('1', 5000))
            . sprintf("response 2: %s\n", str_repeat('2', 5000))
            . sprintf("response 3: %s\n", str_repeat('3', 5000))
            . $held
            . 'errors seen by the application: ["a notice of the application after the run"]' . "\n"
            . "heartbeats queued: 1\n"
            . "heartbeats that the log says were sent: 1\n"
            . "heartbeats that the log says were skipped: 0\n";
        $this->assertSame($expected, $process->getOutput(), 'byte for byte: nothing of the build, and nothing cut from what came after it');
        $this->assertStringNotContainsString('printed by the list handler', $process->getOutput());
    }
}
