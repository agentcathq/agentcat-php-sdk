<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Identity;
use AgentCat\ToolCall;

/** An `identify` hook of `config/agentcat.php` that names the actor after the call's `X-User` header. */
final class HeaderIdentity
{
    public function __invoke(ToolCall $call): Identity
    {
        return new Identity($call->headers['x-user'] ?? 'nobody');
    }
}
