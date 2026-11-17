<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/** `beta`: a title, a description of 2,000 characters, an input schema and an output schema. */
final class BetaTool extends Tool
{
    public function __construct()
    {
        $this->name = 'beta';
        $this->title = 'Beta Tool';
        $this->description = str_repeat('b', 2000);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return ['n' => $schema->integer()->description('The number')];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return ['sum' => $schema->integer()];
    }

    public function handle(Request $request): ResponseFactory
    {
        return Response::make(Response::text('1'))->withStructuredContent(['sum' => 1]);
    }
}
