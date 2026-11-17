<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * `stream`: a generator that yields a progress notification, waits 50 ms, yields another, waits 50 ms, then `done`.
 *
 * laravel/mcp after 1.2.0 sends a tool's `notifications/progress` only for a request with `_meta.progressToken`, and
 * puts the token in the notification; the releases up to 1.2.0 send it for every request. So a test that expects the
 * progress sends a token.
 */
#[Name('stream')]
#[Description('Stream')]
final class StreamingTool extends Tool
{
    /** @return \Generator<int, Response> */
    public function handle(Request $request): \Generator
    {
        ToolCalls::record('stream', $request->all());
        yield Response::notification('notifications/progress', ['progress' => 1]);
        usleep(50_000);
        yield Response::notification('notifications/progress', ['progress' => 2]);
        usleep(50_000);
        yield Response::text('done');
    }
}
