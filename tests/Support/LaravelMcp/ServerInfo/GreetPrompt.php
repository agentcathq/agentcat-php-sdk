<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/** `greet`: a prompt with one argument. */
final class GreetPrompt extends Prompt
{
    public function __construct()
    {
        $this->name = 'greet';
        $this->description = 'Greets';
    }

    /** @return array<int, Argument> */
    #[\Override]
    public function arguments(): array
    {
        return [new Argument('who', 'Whom to greet', true)];
    }

    public function handle(Request $request): Response
    {
        return Response::text('Hello');
    }
}
