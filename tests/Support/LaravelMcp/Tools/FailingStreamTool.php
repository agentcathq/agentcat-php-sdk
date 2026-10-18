<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/** `stream-fails`: a generator that yields one progress notification, then throws `\DomainException('stream broke')`. */
#[Name('stream-fails')]
#[Description('Stream fails')]
final class FailingStreamTool extends Tool
{
    /** @return \Generator<int, Response> */
    public function handle(Request $request): \Generator
    {
        ToolCalls::record('stream-fails', $request->all());
        yield Response::notification('notifications/progress', ['progress' => 1]);

        throw new \DomainException('stream broke');
    }
}
