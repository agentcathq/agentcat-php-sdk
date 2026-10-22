<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\BoomTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\OwnTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\RefusesTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\StructuredTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * `conformance-server` 2.0.0 with the tools of `tests/Support/McpSdk/Tools`: `echo`, `structured`, `refuses`, `boom`
 * and `own`. The constructor sets the tools and the attributes name the server: redeclaring a property would need
 * `#[\Override]`, which PHP accepts on a property only from 8.5 (see `EmptyServer`).
 */
#[Name('conformance-server')]
#[Version('2.0.0')]
final class ConformanceServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class, StructuredTool::class, RefusesTool::class, BoomTool::class, OwnTool::class];
    }
}
