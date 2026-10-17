<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * Every server tracked in this process, whichever `Client` tracked it. The first `track()` of a server owns it, and
 * a later one from any client changes nothing. An entry goes away with its server. A process-wide flag also records
 * that a server could not be tracked or read.
 *
 * @internal
 */
final class TrackedServers
{
    /** @var \WeakMap<object, TrackedServer>|null */
    private static ?\WeakMap $servers = null;

    private static bool $incomplete = false;

    /** The tracking state of the server, or null when no client tracks it. */
    public static function get(object $server): ?TrackedServer
    {
        return self::servers()[$server] ?? null;
    }

    public static function add(object $server, TrackedServer $state): void
    {
        self::servers()[$server] = $state;
    }

    /**
     * Records that AgentCat could not track a server, or could not read a built one, in this process. It logged why.
     * A server it cannot find afterwards may be that one: `publishCustomEvent()` then logs instead of throwing.
     */
    public static function markIncomplete(): void
    {
        self::$incomplete = true;
    }

    /** True once `markIncomplete()` ran in this process. */
    public static function incomplete(): bool
    {
        return self::$incomplete;
    }

    /** Tests only: forgets `markIncomplete()`. The tracked servers stay. */
    public static function resetIncomplete(): void
    {
        self::$incomplete = false;
    }

    /** @return \WeakMap<object, TrackedServer> */
    private static function servers(): \WeakMap
    {
        return self::$servers ??= new \WeakMap();
    }
}
