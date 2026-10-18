<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * A laravel/mcp server without tools, resources or prompts. The attributes name it: overriding `$name` would need
 * `#[\Override]` on a property, which PHP accepts only from 8.5.
 */
#[Name('empty-server')]
#[Version('1.0.0')]
final class EmptyServer extends Server {}
