<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\McpSdkAdapter;
use Mcp\Server;
use Mcp\Server\Builder;

/**
 * Runtime drift in what a Builder's build() assembled, or in a built server (C6, README §4.5 rule 4). The first drift
 * on an object logs the compatibility line; later ones on the same object log nothing. Entries go away with their
 * object.
 *
 * @internal
 */
final class Drift
{
    /** @var \WeakMap<Builder|Server, bool>|null */
    private static ?\WeakMap $reported = null;

    public static function report(Builder|Server $object): void
    {
        self::$reported ??= new \WeakMap();
        if (self::$reported[$object] ?? false) {
            return;
        }
        self::$reported[$object] = true;
        Log::warning(McpSdkAdapter::COMPATIBILITY_LINE);
    }
}
