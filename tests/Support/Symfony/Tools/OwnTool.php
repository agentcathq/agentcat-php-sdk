<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;

/**
 * `own`: declares its own required `session_id` (mcp/sdk names the input property after the parameter). Answers `ok`.
 */
final class OwnTool
{
    #[McpTool(name: 'own', description: 'Own')]
    public function __invoke(string $session_id): string
    {
        ToolCalls::record('own', ['session_id' => $session_id]);

        return 'ok';
    }
}
