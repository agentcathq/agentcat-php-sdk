<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/** `structured`: no input; declares an object `outputSchema` and answers `{"echoed": "x"}` with one text block `x`. */
#[Name('structured')]
#[Description('Structured')]
final class StructuredTool extends Tool
{
    /** @return array<string, mixed> */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return ['echoed' => $schema->string()];
    }

    public function handle(Request $request): ResponseFactory
    {
        ToolCalls::record('structured', $request->all());

        return Response::make(Response::text('x'))->withStructuredContent(['echoed' => 'x']);
    }
}
