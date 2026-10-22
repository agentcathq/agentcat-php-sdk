<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * The sleep of a delivery that waits for a retry. A host can disable `usleep()` (`disable_functions`).
 *
 * @internal
 */
final class Sleep
{
    /**
     * Sleeps with `usleep()`, or with `time_nanosleep()` where `usleep()` does not exist; null where neither exists. The
     * check runs once, when the sleep is made.
     *
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether a function exists; default `function_exists()`
     *
     * @return (\Closure(float): void)|null sleeps the seconds it gets: 0 through 1 (`CurlSupport::waitSeconds()`)
     */
    public static function closure(?\Closure $functionExists = null): ?\Closure
    {
        $exists = $functionExists ?? function_exists(...);
        if ($exists('usleep')) {
            return static function (float $seconds): void {
                usleep((int) ($seconds * 1e6));
            };
        }
        if ($exists('time_nanosleep')) {
            return static function (float $seconds): void {
                $whole = (int) $seconds;
                time_nanosleep($whole, (int) (($seconds - $whole) * 1e9));
            };
        }

        return null;
    }
}
