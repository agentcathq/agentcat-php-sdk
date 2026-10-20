<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\Tools;

use AgentCat\Tests\Support\ToolCalls;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * A customer's own `get_more_tools`, which declares its own required `session_id`. With report-missing on, AgentCat's
 * tool of that name shadows it and answers its calls. Answers `mine`.
 */
#[Name('get_more_tools')]
#[Description('Mine')]
final class CustomerGetMoreTools extends Tool
{
    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return ['session_id' => $schema->string()->required()];
    }

    public function handle(Request $request): Response
    {
        ToolCalls::record('get_more_tools', $request->all());

        return Response::text('mine');
    }
}
