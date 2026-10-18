<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * `echo`: `{msg: string}`, required; answers `echo: <msg>`. The attributes name the tools of these tests: overriding
 * `$name` would need `#[\Override]` on a property, which PHP accepts only from 8.5 (see `EmptyServer`).
 */
#[Name('echo')]
#[Description('Echo')]
final class EchoTool extends Tool
{
    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return ['msg' => $schema->string()->required()];
    }

    public function handle(Request $request): Response
    {
        ToolCalls::record('echo', $request->all());

        return Response::text('echo: ' . (is_string($request->get('msg')) ? $request->get('msg') : ''));
    }
}
