<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/** `refuses`: answers an error result without throwing. */
final class RefusesTool
{
    #[McpTool(name: 'refuses', description: 'Refuses')]
    public function __invoke(): CallToolResult
    {
        ToolCalls::record('refuses', []);

        return CallToolResult::error([new TextContent('not allowed')]);
    }
}
