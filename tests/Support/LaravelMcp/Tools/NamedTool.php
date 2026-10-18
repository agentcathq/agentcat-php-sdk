<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** A tool without input, named in its constructor. Answers `ok`. */
final class NamedTool extends Tool
{
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function handle(Request $request): Response
    {
        ToolCalls::record($this->name, $request->all());

        return Response::text('ok');
    }
}
