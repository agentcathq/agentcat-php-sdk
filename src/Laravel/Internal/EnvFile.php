<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Internal;

/**
 * Edits one `KEY=value` line of a dotenv file and keeps every other byte, line endings included. Used by
 * `agentcat:install` only. It throws when the file cannot be written: an artisan command may fail loudly, and this is
 * not runtime telemetry.
 *
 * @internal
 */
final class EnvFile
{
    /** Replaces the first `KEY=` line with `KEY=value`, or appends one. The value is written literally. */
    public static function set(string $path, string $key, string $value): void
    {
        $contents = self::read($path);
        $line = $key . '=' . $value;
        $count = 0;
        $changed = preg_replace_callback(self::pattern($key), static fn(): string => $line, $contents, 1, $count);
        if ($changed === null || $count === 0) {
            $changed = self::append($contents, $line);
        }
        if ($changed === $contents) {
            return;
        }
        if (@file_put_contents($path, $changed, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . $path);
        }
    }

    /** Appends `KEY=` when the file exists and has no `KEY=` line. Never creates the file. */
    public static function ensure(string $path, string $key): void
    {
        if (!is_file($path) || preg_match(self::pattern($key), self::read($path)) === 1) {
            return;
        }
        self::set($path, $key, '');
    }

    private static function pattern(string $key): string
    {
        return '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';
    }

    private static function read(string $path): string
    {
        $contents = is_file($path) ? @file_get_contents($path) : '';

        return $contents === false ? '' : $contents;
    }

    private static function append(string $contents, string $line): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        return $contents . ($contents === '' || str_ends_with($contents, "\n") ? '' : $eol) . $line . $eol;
    }
}
