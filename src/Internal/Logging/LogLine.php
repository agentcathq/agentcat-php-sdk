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

    /** `[<versions()>]` for this process, resolved once (03 AC-38). */
    public static function prefix(): string
    {
        return self::$prefix ??= '[' . self::versions(SdkVersion::get(), PHP_VERSION, SdkVersion::installed('mcp/sdk'), SdkVersion::installed('laravel/mcp')) . ']';
    }

    /** `sdk=<v> php=<v>` and the installed MCP libraries; `mcp=unknown` when none is (TS `mcp=`/`mcp2=`). */
    public static function versions(string $sdk, string $php, ?string $mcpSdk, ?string $laravelMcp): string
    {
        $parts = ['sdk=' . $sdk, 'php=' . $php];
        if ($mcpSdk !== null) {
            $parts[] = 'mcp=' . $mcpSdk;
        }
        if ($laravelMcp !== null) {
            $parts[] = 'laravel-mcp=' . $laravelMcp;
        }
        if ($mcpSdk === null && $laravelMcp === null) {
            $parts[] = 'mcp=unknown';
        }

        return implode(' ', $parts);
    }

    /** `[<ISO time with milliseconds, UTC>] <prefix> <message>`; `$now` defaults to the current time. */
    public static function format(string $message, ?\DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));

        return sprintf('[%s] %s %s', $now->format('Y-m-d\TH:i:s.v\Z'), self::prefix(), $message);
    }
}
