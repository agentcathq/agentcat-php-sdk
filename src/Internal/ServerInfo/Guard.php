<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * The memory of the heartbeat: when the first check of this process ran, and when the last attempt of each project
 * and server name started. It holds times only, in static memory, so it ends with the process (under PHP-FPM, with the
 * request); nothing is written anywhere. It limits how often a process sends: the heartbeat is correct without it.
 *
 * @internal
 */
final class Guard
{
    /** A memory younger than this may have been made for this one request: a plain tool call starts no heartbeat in it. */
    public const float MEMORY_SECONDS = 60.0;

    /** The time between two attempts of one key, whether the first one was sent or failed. */
    public const float INTERVAL_SECONDS = 3600.0;

    /** A full guard is emptied before it takes one more key. */
    public const int MAX_KEYS = 1000;

    private static ?float $firstCheck = null;

    /** @var array<string, float> the start of the last attempt, by project and server name */
    private static array $attempts = [];

    /**
     * Notes the first check of this memory, then tells whether this check can start a heartbeat: always for a tool
     * list or a feedback note (`$always`), else only when the memory is `MEMORY_SECONDS` old.
     *
     * @param float $now seconds on a monotonic clock
     */
    public static function open(bool $always, float $now): bool
    {
        self::$firstCheck ??= $now;

        return $always || $now - self::$firstCheck >= self::MEMORY_SECONDS;
    }

    /**
     * True when a heartbeat is due for the key, and the attempt then starts now: the same key is due again
     * `INTERVAL_SECONDS` later.
     *
     * @param float $now seconds on a monotonic clock
     */
    public static function claim(string $projectId, string $serverName, float $now): bool
    {
        $key = self::key($projectId, $serverName);
        $last = self::$attempts[$key] ?? null;
        if ($last !== null && $now - $last < self::INTERVAL_SECONDS) {
            return false;
        }
        if ($last === null && count(self::$attempts) >= self::MAX_KEYS) {
            self::$attempts = [];
        }
        self::$attempts[$key] = $now;

        return true;
    }

    /** One string for a project and a server name; two different pairs never give the same one. */
    public static function key(string $projectId, string $serverName): string
    {
        return serialize([$projectId, $serverName]);
    }

    /** Tests only: an empty memory, as a new process has. */
    public static function forget(): void
    {
        self::$firstCheck = null;
        self::$attempts = [];
    }
}
