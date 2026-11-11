<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

/** Compare reflected types in the namespace spelling shared by the supported releases. */
final class NamespaceNames
{
    public static function legacy(string $type): string
    {
        return strtr($type, [
            'Mcp\\Server\\Server' => 'Mcp\\Server',
            'Mcp\\Server\\Capability\\' => 'Mcp\\Capability\\',
            'Mcp\\Server\\Event\\' => 'Mcp\\Event\\',
            'Mcp\\Server\\Exception\\' => 'Mcp\\Exception\\',
            'Mcp\\Schema\\RequestMeta' => 'Mcp\\Server\\Stateless\\RequestMeta',
        ]);
    }
}
