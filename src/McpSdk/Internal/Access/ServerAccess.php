<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use Mcp\Server;
use Mcp\Server\Protocol;

/**
 * The private members of a built mcp/sdk server that AgentCat reads (D21), to find the tracking state of a server
 * built from a tracked Builder. Reads only. Each closure is bound to the class that declares the member, so PHPStan
 * checks the member in that class's scope. Both members are readonly: the server and its protocol set them once.
 *
 * @internal
 */
final class ServerAccess
{
    #[TouchesPrivateMember(class: Server::class, member: 'protocol', type: Protocol::class, readonly: true)]
    public static function protocol(Server $server): Protocol
    {
        $read = \Closure::bind(static fn(Server $s): Protocol => $s->protocol, null, Server::class);

        return $read($server);
    }

    /** @return array<mixed> the handlers build() assembled, AgentCat's included; the entries are not checked here */
    #[TouchesPrivateMember(class: Protocol::class, member: 'requestHandlers', type: 'array', readonly: true)]
    public static function requestHandlers(Protocol $protocol): array
    {
        $read = \Closure::bind(static fn(Protocol $p): array => $p->requestHandlers, null, Protocol::class);

        return $read($protocol);
    }
}
