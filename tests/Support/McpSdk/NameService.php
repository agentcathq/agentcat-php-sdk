<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Capability\Completion\ProviderInterface;

/**
 * A tool handler that is also a completion provider. mcp/sdk builds both from the Builder's container, which keeps
 * one instance per class: the tool answer and the completion name the instance that served them.
 */
final class NameService implements ProviderInterface
{
    public function __invoke(string $name): string
    {
        return $this->instance();
    }

    /** @return list<string> */
    #[\Override]
    public function getCompletions(string $currentValue): array
    {
        return [$this->instance()];
    }

    private function instance(): string
    {
        return 'instance ' . spl_object_id($this);
    }
}
