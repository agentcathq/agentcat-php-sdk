<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/** `refuses`: answers an error result without throwing. */
#[Name('refuses')]
#[Description('Refuses')]
final class RefusesTool extends Tool
{
    public function handle(Request $request): Response
    {
        ToolCalls::record('refuses', $request->all());

        return Response::error('not allowed');
    }
}
