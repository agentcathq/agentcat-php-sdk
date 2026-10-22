<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Tests\Support\Thrown;
use AgentCat\Tests\Support\WarmUp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard of the bridge test cases' warm-ups, which run outside a test, where PHPUnit's error handler is off. This
 * file stands in for a file of `src/`: each test names its directory as the source.
 */
final class WarmUpTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function issues(): iterable
    {
        yield 'a deprecation' => [E_USER_DEPRECATED];
        yield 'a notice' => [E_USER_NOTICE];
        yield 'a warning' => [E_USER_WARNING];
    }

    /** An issue that a source file raises while the warm-up includes it is thrown, out of `setUpBeforeClass()`. */
    #[DataProvider('issues')]
    public function testAnIssueFromASourceFileFailsTheWarmUp(int $level): void
    {
        $thrown = Thrown::by(\ErrorException::class, static function () use ($level): void {
            WarmUp::run(static function () use ($level): void {
                trigger_error('raised at include time', $level);
            }, __DIR__ . '/');
        });

        $this->assertStringStartsWith('raised at include time (raised from ' . __FILE__ . ':', $thrown->getMessage());
        $this->assertSame($level, $thrown->getSeverity());
        $this->assertSame(__FILE__, $thrown->getFile());
    }

    /** @return iterable<string, array{string, bool}> the source directory, and whether the issue is suppressed */
    public static function otherIssues(): iterable
    {
        yield 'from another file, as vendor/' => ['/nonexistent/src/', false];
        yield 'from a source file, suppressed with @' => [__DIR__ . '/', true];
    }

    /** Any other issue goes to the handler that was in place, as without the guard. */
    #[DataProvider('otherIssues')]
    public function testAnyOtherIssueGoesToTheHandlerInPlace(string $source, bool $suppressed): void
    {
        $seen = new \ArrayObject();
        set_error_handler(static function (int $level, string $message) use ($seen): bool {
            $seen->append($message);

            return true;
        });

        try {
            WarmUp::run(static function () use ($suppressed): void {
                $suppressed ? @trigger_error('not ours', E_USER_DEPRECATED) : trigger_error('not ours', E_USER_DEPRECATED);
            }, $source);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['not ours'], $seen->getArrayCopy());
    }

    /** An issue that the handler in place declines (it returns false) goes on to PHP's own handling, as before. */
    public function testAnIssueThatTheHandlerInPlaceDeclinesGoesOnToPhp(): void
    {
        $declined = new \ArrayObject();
        set_error_handler(static function (int $level, string $message) use ($declined): bool {
            $declined->append($message);

            return false;
        });
        error_clear_last();

        try {
            WarmUp::run(static function (): void {
                @trigger_error('declined', E_USER_DEPRECATED); // suppressed, so PHP records it and prints nothing
            }, '/nonexistent/src/');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['declined'], $declined->getArrayCopy());
        $this->assertSame('declined', error_get_last()['message'] ?? null);
    }
}
