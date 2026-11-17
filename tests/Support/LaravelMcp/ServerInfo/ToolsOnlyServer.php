<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use AgentCat\Tests\Support\LaravelMcp\Tools\NamedTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\Methods\ListTools;

/** A server with tools only: its method map has no handler for resources, resource templates or prompts. */
#[Name('tools-only')]
#[Version('1.0.0')]
final class ToolsOnlyServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [new NamedTool('alpha')];
        $this->methods = ['tools/list' => ListTools::class, 'tools/call' => CallTool::class, 'initialize' => Initialize::class];
    }
}
