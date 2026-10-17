<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

/** An invokable tool handler with a class-typed dependency: mcp/sdk's default container autowires it. */
final readonly class WiredTool
{
    public function __construct(private NameService $names) {}

    public function __invoke(string $name): string
    {
        return 'wired ' . $name . ' (' . $this->names::class . ')';
    }
}
