<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use AgentCat\Internal\Support\StrictErrors;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * The protected members of a laravel/mcp server that AgentCat reads (D21). Reads only: AgentCat changes the method
 * map through the public `addMethod()`. Each closure is bound to `Server`, so PHPStan checks the member in its scope.
 *
 * @internal
 */
final class ServerAccess
{
    /**
     * @return array<mixed> JSON-RPC method => handler class (or container key); the entries are not checked here
     *
     * @phpstan-impure
     */
    #[TouchesPrivateMember(class: Server::class, member: 'methods', type: 'array', visibility: 'protected')]
    public static function methods(Server $server): array
    {
        $read = \Closure::bind(static fn(Server $s): array => $s->methods, null, Server::class);

        return $read($server);
    }

    /** @phpstan-impure */
    #[TouchesPrivateMember(class: Server::class, member: 'transport', type: Transport::class, visibility: 'protected')]
    public static function transport(Server $server): Transport
    {
        $read = \Closure::bind(static fn(Server $s): Transport => $s->transport, null, Server::class);

        return $read($server);
    }

    /** Reads every member once with warnings as exceptions: a renamed or removed member throws here. */
    public static function probe(Server $server): void
    {
        StrictErrors::run(static function () use ($server): void {
            self::methods($server);
            self::transport($server);
        });
    }
}
