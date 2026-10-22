<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * Every server tracked in this process, whichever `Client` tracked it. The first `track()` of a server owns it, and
 * a later one from any client changes nothing. An entry goes away with its server.
 *
 * @internal
 */
final class TrackedServers
{
    /** @var \WeakMap<object, TrackedServer>|null */
    private static ?\WeakMap $servers = null;

    /** The tracking state of the server, or null when no client tracks it. */
    public static function get(object $server): ?TrackedServer
    {
        return self::servers()[$server] ?? null;
    }

    public static function add(object $server, TrackedServer $state): void
    {
        self::servers()[$server] = $state;
    }

    /** @return \WeakMap<object, TrackedServer> */
    private static function servers(): \WeakMap
    {
        return self::$servers ??= new \WeakMap();
    }
}
