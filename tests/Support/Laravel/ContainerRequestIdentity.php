<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Identity;
use Illuminate\Container\Container;

/**
 * An `identify` hook the Laravel way, as `auth()->id()` reads the user: the current request of the container when the
 * hook runs, here its `X-User` header. It ignores the `ToolCall` it gets.
 */
final class ContainerRequestIdentity
{
    public function __invoke(): Identity
    {
        return new Identity(Container::getInstance()->make('request')->header('X-User', 'nobody'));
    }
}
