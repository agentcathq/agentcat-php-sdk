<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;

/** `boom`: throws a `RuntimeException` caused by a `LogicException`, which mcp/sdk answers with -32603. */
final class BoomTool
{
    #[McpTool(name: 'boom', description: 'Boom')]
    public function __invoke(): never
    {
        ToolCalls::record('boom', []);

        throw new \RuntimeException('tool boom', 0, new \LogicException('cause'));
    }
}
