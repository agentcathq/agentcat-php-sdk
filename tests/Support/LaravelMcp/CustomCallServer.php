<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * A server with its own `tools/call` handler, `CustomCallTool`, and the `echo` tool. The constructor sets the entry
 * before the container's `afterResolving` callbacks run, like a `$methods` default: redeclaring the property would
 * need `#[\Override]`, which PHP accepts on a property only from 8.5.
 */
#[Name('custom-call-server')]
#[Version('1.0.0')]
final class CustomCallServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class];
        $this->addMethod('tools/call', CustomCallTool::class);
    }
}
