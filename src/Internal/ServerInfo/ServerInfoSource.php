<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * What the server info heartbeat reads from one MCP server, with the context of one request (its session, its server
 * context). Each adapter implements it over its library's own list handlers. Every method can call the customer's
 * code and can throw.
 *
 * @internal
 */
interface ServerInfoSource
{
    public const string TOOLS = 'tools';

    public const string RESOURCES = 'resources';

    public const string RESOURCE_TEMPLATES = 'resourceTemplates';

    public const string PROMPTS = 'prompts';

    /** The lists of a heartbeat, by their key in `features`: the tool list first. */
    public const array LISTS = [self::TOOLS, self::RESOURCES, self::RESOURCE_TEMPLATES, self::PROMPTS];

    /** The title of the server's implementation info; '' when it has none. */
    public function title(): string;

    /** The server's instructions; '' when it has none. */
    public function instructions(): string;

    /**
     * One page of a list (a `LISTS` name), from the server's own handler of that list request: the items as the wire
     * carries them (JSON trees) and the next cursor (null on the last page). Null when the server has no handler for
     * the list.
     *
     * @return array{list<mixed>, ?string}|null
     */
    public function page(string $list, ?string $cursor): ?array;

    /**
     * True when the tool pages already carry what AgentCat adds to a tool list (its parameters, `get_more_tools`);
     * false when they are the server's own tools, which a client gets with those additions.
     */
    public function toolsAdvertised(): bool;
}
