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

/** `own`: declares its own required `session_id`. Answers `ok`. */
#[Name('own')]
#[Description('Own')]
final class OwnTool extends Tool
{
    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return ['session_id' => $schema->string()->required()];
    }

    public function handle(Request $request): Response
    {
        ToolCalls::record('own', $request->all());

        return Response::text('ok');
    }
}
