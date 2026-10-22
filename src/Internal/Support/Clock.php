<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * The clock of durations and deadlines. A host can disable `hrtime()` (`disable_functions`).
 *
 * @internal
 */
final class Clock
{
    /**
     * Seconds on `hrtime()`, which is monotonic, or on `microtime()` where `hrtime()` does not exist: the wall clock,
     * which steps when the system time is set. The check runs once, when the clock is made.
     *
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether `hrtime()` exists; default `function_exists()`
     *
     * @return \Closure(): float
     */
    public static function monotonic(?\Closure $functionExists = null): \Closure
    {
        if (($functionExists ?? function_exists(...))('hrtime')) {
            return static fn(): float => hrtime(true) / 1e9;
        }

        return static fn(): float => microtime(true);
    }
}
