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

    /** Never throws. */
    public static function write(string $message): void
    {
        try {
            if (preg_match('/fail|error/i', $message) === 1) {
                $level = LogLevel::ERROR;
            } elseif (str_starts_with($message, 'WARN:') || str_contains($message, 'Warning:')) {
                $level = LogLevel::WARNING;
            } else {
                $level = LogLevel::INFO;
            }
            self::$logger ??= new FileLogger();
            self::$logger->log($level, $message);
        } catch (\Throwable) {
            // Logging must never affect the host server.
        }
    }
}
