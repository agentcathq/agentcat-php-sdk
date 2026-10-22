<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Illuminate\Container\Container;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/** `request-echo`: records the arguments of its own `Request` and of the container's `mcp.request`. Answers `ok`. */
#[Name('request-echo')]
#[Description('Request echo')]
final class RequestEchoTool extends Tool
{
    public function handle(Request $request): Response
    {
        $bound = Container::getInstance()->make('mcp.request');
        ToolCalls::record('request-echo', ['request' => $request->all(), 'container' => $bound instanceof Request ? $bound->all() : null]);

        return Response::text('ok');
    }
}
