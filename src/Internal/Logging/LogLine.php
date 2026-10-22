<?php

declare(strict_types=1);

namespace AgentCat\Internal\Logging;

use AgentCat\Internal\SdkVersion;

/**
 * The text of one SDK log line, as the log file and the diagnostics tee carry it (TypeScript `writeToLog`).
 *
 * @internal
 */
final class LogLine
{
    private static ?string $prefix = null;

    /** `[sdk=<v> php=<v> mcp=<v>]`, resolved once per process (03 AC-38). */
    public static function prefix(): string
    {
        return self::$prefix ??= sprintf('[sdk=%s php=%s mcp=%s]', SdkVersion::get(), PHP_VERSION, SdkVersion::installed('mcp/sdk') ?? 'unknown');
    }

    /** `[<ISO time with milliseconds, UTC>] <prefix> <message>`; `$now` defaults to the current time. */
    public static function format(string $message, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));

        return sprintf('[%s] %s %s', $now->format('Y-m-d\TH:i:s.v\Z'), self::prefix(), $message);
    }
}
