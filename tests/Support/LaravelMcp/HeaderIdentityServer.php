<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * `header-identity-server` with one tool, `echo`, for the HTTP tests that send each request through Laravel's kernel
 * (`Mcp::web()`). No tool of it declares an injected parameter, so tracking it logs nothing for its tools.
 */
#[Name('header-identity-server')]
#[Version('1.0.0')]
final class HeaderIdentityServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class];
    }
}
