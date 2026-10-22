<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/** `structured`: no input; declares an object `outputSchema` and answers `{"echoed": "x"}` with one text block `x`. */
final class StructuredTool
{
    #[McpTool(name: 'structured', description: 'Structured', outputSchema: ['type' => 'object', 'properties' => ['echoed' => ['type' => 'string']]])]
    public function __invoke(): CallToolResult
    {
        ToolCalls::record('structured', []);

        return new CallToolResult([new TextContent('x')], structuredContent: ['echoed' => 'x']);
    }
}
