<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\ServerInfo\ErrorShield;
use PHPUnit\Framework\TestCase;

/** Real PHP error handlers; each test leaves the stack of handlers as it found it. */
final class ErrorShieldTest extends TestCase
{
    /** @var list<array{int, string, string, int}> */
    private array $seen = [];

    public function testWhileTheBuildRunsAWarningIsSwallowedAndAfterItTheHandlerFromBeforeIsBackOnTop(): void
    {
        $application = $this->application(true);
        set_error_handler($application);

        try {
            $shield = ErrorShield::start();
            trigger_error('inside the build', E_USER_WARNING);
            $shield->end();
            $top = $this->top();
            trigger_error('after the build', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        $this->assertSame($application, $top, 'the handler of the application, and no other');
        $this->assertSame(['after the build'], array_column($this->seen, 1));
    }

    public function testAHandlerThatTheBuildLeftStaysAndTheShieldBelowItPassesEachErrorOnWithTheSameArguments(): void
    {
        $application = $this->application(true);
        $left = [];
        $leftover = static function (int $severity, string $message) use (&$left): bool {
            $left[] = $message;

            return true;
        };
        set_error_handler($application);

        try {
            $shield = ErrorShield::start();
            set_error_handler($leftover); // a list method sets it and does not restore it
            $shield->end();
            $top = $this->top();
            trigger_error('reaches the handler that was left', E_USER_NOTICE);
            restore_error_handler(); // the customer's code removes its own handler later
            $line = __LINE__ + 1;
            trigger_error('reaches the handler from before', E_USER_WARNING);
            restore_error_handler(); // the inactive shield
        } finally {
            restore_error_handler();
        }

        $this->assertSame($leftover, $top, 'the shield does not pop a handler that it did not set');
        $this->assertSame(['reaches the handler that was left'], $left);
        $this->assertSame([[E_USER_WARNING, 'reaches the handler from before', __FILE__, $line]], $this->seen);
    }

    public function testWhenTheBuildRemovedTheShieldTheEndPopsNothing(): void
    {
        $application = $this->application(true);
        set_error_handler($application);

        try {
            $shield = ErrorShield::start();
            restore_error_handler(); // a list method restores one handler more than it set
            $shield->end();
            $top = $this->top();
        } finally {
            restore_error_handler();
        }

        $this->assertSame($application, $top);
    }

    public function testAfterTheEndTheShieldReturnsWhatTheReplacedHandlerReturns(): void
    {
        foreach ([true, false] as $result) {
            $application = $this->application($result);
            set_error_handler($application);

            try {
                $shield = ErrorShield::start();
                $handler = $this->shieldOnTop();
                $active = $handler(E_USER_NOTICE, 'during', 'file.php', 1);
                $shield->end();
                $inactive = $handler(E_USER_NOTICE, 'after', 'file.php', 2);
            } finally {
                restore_error_handler();
            }

            $this->assertTrue($active, 'during the build every error is swallowed');
            $this->assertSame($result, $inactive);
        }
        $this->assertSame([[E_USER_NOTICE, 'after', 'file.php', 2], [E_USER_NOTICE, 'after', 'file.php', 2]], $this->seen);
    }

    public function testWithNoHandlerBeforeItTheShieldReturnsFalseAfterTheEndSoPhpHandlesTheError(): void
    {
        set_error_handler(null);

        try {
            $shield = ErrorShield::start();
            $handler = $this->shieldOnTop();
            $shield->end();
            $top = $this->top();
        } finally {
            restore_error_handler();
        }

        $this->assertNull($top);
        $this->assertFalse($handler(E_USER_WARNING, 'after', 'file.php', 1));
    }

    /** @return \Closure(int, string, string, int): bool */
    private function application(bool $result): \Closure
    {
        return function (int $severity, string $message, string $file, int $line) use ($result): bool {
            $this->seen[] = [$severity, $message, $file, $line];

            return $result;
        };
    }

    private function top(): mixed
    {
        $top = set_error_handler(static fn(): bool => true);
        restore_error_handler();

        return $top;
    }

    /** @return \Closure(int, string, string, int): bool */
    private function shieldOnTop(): \Closure
    {
        $top = $this->top();
        $this->assertInstanceOf(\Closure::class, $top);

        return $top;
    }
}
