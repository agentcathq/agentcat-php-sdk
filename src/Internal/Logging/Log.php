<?php

declare(strict_types=1);

namespace AgentCat\Internal\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** @internal */
final class Log
{
    private static ?LoggerInterface $logger = null;

    /** True once `setLogger()` or `adopt()` chose the logger; the lazy file fallback does not count. */
    private static bool $chosen = false;

    /** @var array{logger: ?LoggerInterface, chosen: bool}|null the routing `divert()` replaced; null without one */
    private static ?array $beforeDiversion = null;

    /** @var (\Closure(string): void)|null the diagnostics tee */
    private static ?\Closure $sink = null;

    /** A non-null logger wins over every later `adopt()`; null goes back to the file fallback. */
    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
        self::$chosen = $logger instanceof LoggerInterface;
    }

    /**
     * Routes every later line to the first logger adopted in the process, unless `setLogger()` chose one (D30, D38):
     * the PSR-3 logger of the first tracked server.
     */
    public static function adopt(LoggerInterface $logger): void
    {
        if (self::$chosen) {
            return;
        }
        self::$logger = $logger;
        self::$chosen = true;
    }

    /**
     * Sends every later line to `$logger` only, until `endDiversion()`; `adopt()` changes nothing meanwhile. A second
     * `divert()` changes the target and keeps the routing from before the first one. `AgentCat::fake()` uses it.
     */
    public static function divert(LoggerInterface $logger): void
    {
        self::$beforeDiversion ??= ['logger' => self::$logger, 'chosen' => self::$chosen];
        self::$logger = $logger;
        self::$chosen = true;
    }

    /** Puts back the routing from before the first `divert()`: the logger, and whether `adopt()` can choose one. */
    public static function endDiversion(): void
    {
        if (self::$beforeDiversion === null) {
            return;
        }
        self::$logger = self::$beforeDiversion['logger'];
        self::$chosen = self::$beforeDiversion['chosen'];
        self::$beforeDiversion = null;
    }

    /**
     * TypeScript `setDiagnosticsSink`: one sink, replaced by a later call; null removes it. It gets every line, with
     * the log-file prefix (`LogLine::format()`), before the logger does, except while a diversion is active.
     *
     * @param (\Closure(string): void)|null $sink
     */
    public static function setDiagnosticsSink(?\Closure $sink): void
    {
        self::$sink = $sink;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$logger = null;
        self::$chosen = false;
        self::$beforeDiversion = null;
        self::$sink = null;
    }

    /** Never throws. The level comes from the line prefix only. */
    public static function write(string $message): void
    {
        if (str_starts_with($message, 'ERROR:') || str_starts_with($message, 'Error:')) {
            self::emit(LogLevel::ERROR, $message);
        } elseif (str_starts_with($message, 'WARN:') || str_contains($message, 'Warning:')) {
            self::emit(LogLevel::WARNING, $message);
        } else {
            self::emit(LogLevel::INFO, $message);
        }
    }

    /** Never throws. */
    public static function error(string $message): void
    {
        self::emit(LogLevel::ERROR, $message);
    }

    /** Never throws. */
    public static function warning(string $message): void
    {
        self::emit(LogLevel::WARNING, $message);
    }

    /** Never throws. */
    public static function info(string $message): void
    {
        self::emit(LogLevel::INFO, $message);
    }

    private static function emit(string $level, string $message): void
    {
        if (self::$sink instanceof \Closure && self::$beforeDiversion === null) { // a fake sends nothing (spec conflict 9)
            try {
                (self::$sink)(LogLine::format($message));
            } catch (\Throwable) {
                // Diagnostics must never break logging.
            }
        }

        try {
            self::$logger ??= new FileLogger();
            self::$logger->log($level, $message);
        } catch (\Throwable) {
            // Logging must never affect the host server.
        }
    }
}
