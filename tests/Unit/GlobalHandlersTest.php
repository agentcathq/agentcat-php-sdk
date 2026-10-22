<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Tests\Support\GlobalHandlers;
use PHPUnit\Framework\TestCase;

/**
 * The test support that puts the error and exception handler stacks back after a bridge test case's warm-up. PHPUnit
 * checks after this test that both stacks are as they were before it.
 */
final class GlobalHandlersTest extends TestCase
{
    public function testRestorePutsBackARemovedHandlerAndRemovesAnAddedOne(): void
    {
        $error = static fn(): bool => false;
        $exception = static function (): void {};
        set_error_handler($error);
        set_exception_handler($exception);

        try {
            $saved = GlobalHandlers::save();
            $this->assertSame([$error, $exception], $this->top(), 'saving leaves the stacks as they are');
            restore_error_handler(); // as Laravel's HandleExceptions::flushState() removes every handler
            restore_exception_handler();
            set_error_handler(static fn(): bool => true); // as Symfony's ErrorHandler stays after a kernel boots
            $saved->restore();

            $this->assertSame([$error, $exception], $this->top());
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }

    /** @return array{mixed, mixed} the error handler and the exception handler on top of their stacks */
    private function top(): array
    {
        $error = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        $exception = set_exception_handler(static function (): void {});
        restore_exception_handler();

        return [$error, $exception];
    }
}
