<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use Laravel\Mcp\Server;

/**
 * The container key under which AgentCat binds its method object for one server (plan E1 header conflict 3). laravel/mcp
 * resolves each entry of a server's method map with `Container::make()`, so the entry can name a key. A reused object
 * ID belongs to a dead server, so overwriting its binding is correct.
 *
 * @internal
 */
final class MethodKey
{
    public static function for(Server $server, string $method): string
    {
        return 'agentcat.laravel-mcp.' . spl_object_id($server) . '.' . $method;
    }
}
