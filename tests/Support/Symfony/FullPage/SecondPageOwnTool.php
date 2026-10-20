<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony\FullPage;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Capability\Attribute\McpTool;

/**
 * `own` of `TestKernel::PAGED`, like `Tools\OwnTool`: declares its own required `session_id` and answers `ok`. The
 * bundle lists a server's tools in the order of their services, and `Tools\OwnTool` is a service before
 * `FullPageTools`; this one is registered after it, so it is alone on page 2.
 */
final class SecondPageOwnTool
{
    #[McpTool(name: 'own', description: 'Own')]
    public function __invoke(string $session_id): string
    {
        ToolCalls::record('own', ['session_id' => $session_id]);

        return 'ok';
    }
}
