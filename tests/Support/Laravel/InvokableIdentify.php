<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Identity;

/**
 * An `identify` hook as `config/agentcat.php` names it: an invokable class that the container builds. It ignores the
 * `ToolCall` it gets.
 */
final readonly class InvokableIdentify
{
    public function __construct(private string $userId) {}

    public function __invoke(): Identity
    {
        return new Identity($this->userId);
    }
}
