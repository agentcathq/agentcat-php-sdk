<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\RequestEchoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/** `request-echo-server` with one tool, `request-echo`. */
#[Name('request-echo-server')]
#[Version('1.0.0')]
final class RequestEchoServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [RequestEchoTool::class];
    }
}
