<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\ServerInfo\OutputGuard;
use PHPUnit\Framework\TestCase;

/** Real output buffers; `OutputGuardProcessTest` has the buffer that PHP can never remove. */
final class OutputGuardTest extends TestCase
{
    public function testWhatIsPrintedDuringTheBuildIsDroppedWithEveryBufferLeftOpenAndABufferFromBeforeStays(): void
    {
        ob_start(); // a buffer of the customer's that was open before the run
        echo 'before ';
        $level = ob_get_level();

        $guard = OutputGuard::start();
        echo 'x';
        echo 'printed by a list handler';
        ob_start();
        echo 'into a buffer of the handler';
        ob_start();
        $guard->end();

        $this->assertSame($level, ob_get_level(), 'every buffer of the build is closed, and the one from before is open');
        $this->assertSame('before ', ob_get_contents(), 'it keeps its content, and nothing of the build is in it');
        echo 'after';
        $this->assertSame('before after', ob_get_clean());
    }

    public function testEachWriteIsDroppedAtOnceSoNothingWaitsInTheGuardsBuffer(): void
    {
        ob_start();
        $guard = OutputGuard::start();
        echo 'x'; // one byte
        $waiting = ob_get_length();
        $guard->end();
        ob_end_clean();

        $this->assertSame(0, $waiting);
    }

    /** PHP removes a buffer whose handler throws on the clean call: the cleanup goes on to the guard's own buffer. */
    public function testABufferWhoseHandlerThrowsOnTheCleanCallDoesNotStopTheEndAndLaterOutputPassesUnchanged(): void
    {
        ob_start(); // stands for the output of the process
        echo 'before ';
        $level = ob_get_level();

        $guard = OutputGuard::start();
        echo 'printed by a list handler';
        ob_start(static fn(string $output, int $phase): string => ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0 ? throw new \RuntimeException('this buffer cannot be cleaned') : $output);
        echo '[left]';
        ob_start();
        echo 'above it';
        $guard->end(); // does not throw

        $left = ob_get_level() - $level;
        $later = str_repeat('later output of the process, byte for byte. ', 200);
        echo $later;
        for ($open = $left; $open > 0; --$open) {
            ob_end_flush(); // a safety net: whatever stayed must not outlive the test
        }

        $this->assertSame(0, $left, 'every buffer of the build is gone');
        $this->assertSame('before ' . $later, ob_get_clean());
    }

    public function testTheEndCanBeCalledTwice(): void
    {
        ob_start();
        $level = ob_get_level();
        $guard = OutputGuard::start();
        $guard->end();
        $guard->end();

        $this->assertSame($level, ob_get_level(), 'the second call removes no buffer of the customer\'s');
        ob_end_clean();
    }
}
