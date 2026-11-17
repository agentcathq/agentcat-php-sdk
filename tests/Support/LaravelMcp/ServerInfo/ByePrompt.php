<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp\ServerInfo;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

/** `bye`: a prompt without arguments. */
final class ByePrompt extends Prompt
{
    public function __construct()
    {
        $this->name = 'bye';
        $this->description = 'Says goodbye';
    }

    public function handle(Request $request): Response
    {
        return Response::text('Bye');
    }
}
