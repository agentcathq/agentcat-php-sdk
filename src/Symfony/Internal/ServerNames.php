<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

/**
 * Which servers `agentcat.exclude_servers` names (D53, by `mcp.servers` name). symfony/config writes a key
 * `my-server` of `mcp.servers` as `my_server`, unless the key holds an underscore or its other spelling is a key too,
 * so a name selects a server by either spelling, as the customer wrote it next to `mcp.servers`.
 *
 * @internal
 */
final class ServerNames
{
    /**
     * @param array<mixed> $names   the names in `exclude_servers`; an entry that is no string names nothing
     * @param list<string> $servers the name of every server, as symfony/mcp-bundle tags its builder
     */
    public static function excluded(string $server, array $names, array $servers): bool
    {
        foreach ($names as $name) {
            if (is_string($name) && self::server($name, $servers) === $server) {
                return true;
            }
        }

        return false;
    }

    /**
     * The server a name selects: the name itself when it is a server, or when symfony/config keeps it as written.
     *
     * @param list<string> $servers
     */
    private static function server(string $name, array $servers): string
    {
        if (in_array($name, $servers, true) || str_contains($name, '_')) {
            return $name;
        }

        return str_replace('-', '_', $name);
    }
}
