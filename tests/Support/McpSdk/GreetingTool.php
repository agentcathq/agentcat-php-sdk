<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

/** An invokable tool handler that needs its container: mcp/sdk cannot build it on its own (a string argument). */
final readonly class GreetingTool
{
    public function __construct(private string $greeting) {}

    public function __invoke(string $name): string
    {
        return $this->greeting . ', ' . $name;
    }
}
