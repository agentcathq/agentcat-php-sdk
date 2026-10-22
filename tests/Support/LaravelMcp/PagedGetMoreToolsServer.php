<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Tests\Support\LaravelMcp\Tools\CustomerGetMoreTools;
use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\NamedTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;

/**
 * 16 tools on laravel/mcp's default pages of 15, like `PagedOwnServer`: `echo` and `t01` to `t14` fill page 1, and the
 * customer's own `get_more_tools` (its own required `session_id`) is alone on page 2.
 */
#[Name('paged-get-more-tools-server')]
#[Version('1.0.0')]
final class PagedGetMoreToolsServer extends Server
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->tools = [EchoTool::class, ...array_map(static fn(int $i): Tool => new NamedTool(sprintf('t%02d', $i)), range(1, 14)), CustomerGetMoreTools::class];
    }
}
