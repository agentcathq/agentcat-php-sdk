<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\ServerInfo\OutputGuard;
use PHPUnit\Framework\TestCase;

final class OutputGuardTest extends TestCase
{
    public function testWhatIsPrintedUnderTheGuardIsDiscardedWithEveryBufferLeftOpen(): void
    {
        ob_start(); // stands for the output of the process
        echo 'before ';
        $level = ob_get_level();

        $guard = OutputGuard::start();
        echo 'x'; // one byte: nothing waits in the guard's buffer
        echo 'printed by a list handler';
        ob_start();
        echo 'into a buffer of the handler';
        ob_start();
        $guard->end();
        $after = ob_get_level();
        echo 'after';

        $this->assertSame('before after', ob_get_clean());
        $this->assertSame($level, $after, 'every buffer of the build is closed');
    }

    /**
     * A buffer that PHP cannot remove stays above the guard's: the cleanup tries each open buffer once and ends. What
     * waited in that buffer when the build ended never reaches the output; what is printed later does.
     */
    public function testABufferThatCannotBeRemovedEndsTheCleanupAndItsContentOfTheBuildIsDiscardedLater(): void
    {
        ob_start(); // stands for the output of the process
        echo 'before ';
        $level = ob_get_level();
        $tries = 0;

        $guard = OutputGuard::start(static function () use (&$tries): bool {
            ++$tries;

            return false; // as ob_end_clean() answers for a buffer that cannot be removed
        });
        echo 'x';
        echo 'printed by a list handler';
        ob_start(); // stands for the buffer that cannot be removed
        echo 'held'; // 4 bytes wait in it when the build ends
        $guard->end();
        $this->assertSame(2, $tries, 'one try for each open buffer, not a loop without end');
        $this->assertSame($level + 2, ob_get_level(), 'both buffers are still there');

        echo 'later';
        ob_end_flush(); // the buffer above goes at last: its content comes down to the guard's buffer
        echo ' and after';
        ob_end_flush();

        $this->assertSame('before later and after', ob_get_clean());
    }
}
