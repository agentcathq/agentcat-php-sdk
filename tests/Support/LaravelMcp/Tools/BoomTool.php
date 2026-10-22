<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/** `boom`: throws a `RuntimeException` caused by a `LogicException`. */
#[Name('boom')]
#[Description('Boom')]
final class BoomTool extends Tool
{
    public function handle(Request $request): never
    {
        ToolCalls::record('boom', $request->all());

        throw new \RuntimeException('tool boom', 0, new \LogicException('cause'));
    }
}
