<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\McpSdkAdapter;
use Mcp\Server\Builder;

/**
 * Runtime drift in what a Builder's build() assembled (C6, README §4.5 rule 4). The first drift on a Builder logs the
 * compatibility line; later ones on the same Builder log nothing. Entries go away with their Builder.
 *
 * @internal
 */
final class Drift
{
    /** @var \WeakMap<Builder, bool>|null */
    private static ?\WeakMap $reported = null;

    public static function report(Builder $builder): void
    {
        self::$reported ??= new \WeakMap();
        if (self::$reported[$builder] ?? false) {
            return;
        }
        self::$reported[$builder] = true;
        Log::warning(McpSdkAdapter::COMPATIBILITY_LINE);
    }
}
