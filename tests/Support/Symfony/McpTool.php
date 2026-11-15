<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use Mcp\Capability\Attribute\McpTool as BaseMcpTool;

/**
 * Symfony matches attribute names and parent classes, not aliases. A subclass exposes the installed MCP attribute's
 * canonical name as its parent, both before and after the mcp/sdk 0.10 namespace move.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class McpTool extends BaseMcpTool {}
