<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Runs code that must throw and hands back the throwable, so a test asserts the message itself. This replaces
 * expectExceptionMessage(), which PHPUnit 13.4 soft-deprecates; its replacement does not exist in PHPUnit 12.5.
 */
final class Thrown
{
    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     * @param \Closure(): mixed $code
     *
     * @return T
     */
    public static function by(string $class, \Closure $code): \Throwable
    {
        try {
            $code();
        } catch (\Throwable $e) {
            Assert::assertInstanceOf($class, $e);

            return $e;
        }

        Assert::fail("Failed asserting that {$class} is thrown.");
    }
}
