<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Laravel;

use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Laravel\Internal\ServerTracker;
use PHPUnit\Framework\TestCase;

final class ConfigFileTest extends TestCase
{
    public function testTheConfigFileHasNoClosureAndOnlyKnownKeys(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/agentcat.php';

        $this->assertIsArray($config);
        array_walk_recursive($config, function (mixed $value): void {
            $this->assertNotInstanceOf(\Closure::class, $value); // config:cache
        });
        $this->assertSame(['Laravel\Boost\Mcp\Boost'], $config['exclude'] ?? null);
        $this->assertSame([], array_diff(array_keys($config), [...ServerTracker::BRIDGE_KEYS, ...array_keys(ConfigOptions::KEYS)]));
    }

    public function testTheConfigFileListsEveryKey(): void
    {
        $config = require dirname(__DIR__, 3) . '/config/agentcat.php';

        $this->assertIsArray($config);
        $this->assertEqualsCanonicalizing([...ServerTracker::BRIDGE_KEYS, ...array_keys(ConfigOptions::KEYS)], array_keys($config));
    }
}
