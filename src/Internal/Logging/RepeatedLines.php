<?php

declare(strict_types=1);

namespace AgentCat\Internal\Logging;

/**
 * Collapses a line that repeats inside one delivery (the drain loops of `EventQueue` and `CurlTransport`): a failure
 * thrown on every pass is logged once, and when the delivery ends, one more line gives the number of repeats.
 * Deliveries nest (a signal drain inside a flush): the outermost one reports. Outside a delivery every line is logged.
 *
 * @internal
 */
final class RepeatedLines
{
    /** The line after a delivery in which `%1$s` repeated `%2$d` more times. */
    private const string REPEATED = '%s (%d more times in this delivery)';

    /** The deliveries running now, nested. */
    private static int $depth = 0;

    /** @var array<string, int> each line logged in the running delivery, with its repeats */
    private static array $repeats = [];

    /** A delivery starts. */
    public static function begin(): void
    {
        ++self::$depth;
    }

    /** Logs `$line`, except a line the running delivery logged already: that one is counted. */
    public static function write(string $line): void
    {
        if (self::$depth > 0) {
            if (array_key_exists($line, self::$repeats)) {
                ++self::$repeats[$line];

                return;
            }
            self::$repeats[$line] = 0;
        }
        Log::write($line);
    }

    /** A delivery ends; the outermost one logs one line for each line that repeated, with the count. */
    public static function end(): void
    {
        if (self::$depth === 0 || --self::$depth > 0) {
            return;
        }
        $repeats = self::$repeats;
        self::$repeats = [];
        foreach ($repeats as $line => $count) {
            if ($count > 0) {
                Log::write(sprintf(self::REPEATED, $line, $count));
            }
        }
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$depth = 0;
        self::$repeats = [];
    }
}
