<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\NamedTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;

/** 15 tools, exactly laravel/mcp's default page: `echo`, then `t01` to `t14` (see `PagedServer`). */
#[Name('full-page-server')]
#[Version('1.0.0')]
final class FullPageServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class, ...array_map(static fn(int $i): Tool => new NamedTool(sprintf('t%02d', $i)), range(1, 14))];
    }
}
