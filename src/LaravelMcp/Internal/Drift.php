<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Logging\Log;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use Laravel\Mcp\Server;

/**
 * What AgentCat cannot read from a tracked server on the request path (README §4.5 rule 4): its name and version, or
 * the HTTP request it answers. The first failure on a server logs the compatibility line; later ones on the same
 * server log nothing. Entries go away with their server.
 *
 * @internal
 */
final class Drift
{
    /** @var \WeakMap<Server, bool>|null */
    private static ?\WeakMap $reported = null;

    public static function report(Server $server): void
    {
        self::$reported ??= new \WeakMap();
        if (self::$reported[$server] ?? false) {
            return;
        }
        self::$reported[$server] = true;
        Log::warning(LaravelMcpAdapter::COMPATIBILITY_LINE);
    }
}
