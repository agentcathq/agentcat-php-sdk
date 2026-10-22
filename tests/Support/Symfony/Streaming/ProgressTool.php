<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\Streaming;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\ClientGateway;

/**
 * `progress`: `{msg: string}`; sends a progress notification, which suspends mcp/sdk's fiber, so the answer is an SSE
 * stream that the client reads after the kernel has handled the request. Answers `done: <msg>`. Outside `Tools/`, so
 * only a test that gives it to a server's registry exposes it.
 */
final class ProgressTool
{
    #[McpTool(name: 'progress', description: 'Reports progress')]
    public function __invoke(string $msg, ClientGateway $gateway): string
    {
        $gateway->progress(0.5, 1.0, 'half way');
        ToolCalls::record('progress', ['msg' => $msg]);

        return 'done: ' . $msg;
    }
}
