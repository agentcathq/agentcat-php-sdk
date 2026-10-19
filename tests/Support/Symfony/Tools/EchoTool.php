<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;

/** `echo`: `{msg: string}`, required; answers `echo: <msg>`. */
final class EchoTool
{
    #[McpTool(name: 'echo', description: 'Echo')]
    public function __invoke(string $msg): string
    {
        ToolCalls::record('echo', ['msg' => $msg]);

        return 'echo: ' . $msg;
    }
}
