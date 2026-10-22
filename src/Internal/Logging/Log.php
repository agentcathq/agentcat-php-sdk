<?php

declare(strict_types=1);

namespace AgentCat\Internal\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** @internal */
final class Log
{
    private static ?LoggerInterface $logger = null;

    public static function setLogger(?LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$logger = null;
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
        try {
            self::$logger ??= new FileLogger();
            self::$logger->log($level, $message);
        } catch (\Throwable) {
            // Logging must never affect the host server.
        }
    }
}
