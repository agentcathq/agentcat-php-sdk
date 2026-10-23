<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ToolReference;

/**
 * The tools of mcp/sdk's registry (D21). For a server tracked after build(), AgentCat replaces each tool with one that
 * advertises its parameters and runs the tool through AgentCat. It writes the member, not registerTool(), which would
 * send the clients a `notifications/tools/list_changed` for each tool. The closures are bound to Registry, so PHPStan
 * checks the member in its scope.
 *
 * @internal
 */
final class RegistryAccess
{
    /**
     * @return array<mixed> the tools by name, in the order they were registered; the entries are not checked here
     *
     * @phpstan-impure
     */
    #[TouchesPrivateMember(class: Registry::class, member: 'tools', type: 'array')]
    public static function tools(Registry $registry): array
    {
        $read = \Closure::bind(static fn(Registry $r): array => $r->tools, null, Registry::class);

        return $read($registry);
    }

    /** @param array<string, ToolReference> $tools */
    #[TouchesPrivateMember(class: Registry::class, member: 'tools', type: 'array')]
    public static function setTools(Registry $registry, array $tools): void
    {
        $write = \Closure::bind(static function (Registry $r) use ($tools): void {
            $r->tools = $tools;
        }, null, Registry::class);
        $write($registry);
    }
}
