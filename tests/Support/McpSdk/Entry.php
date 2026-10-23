<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\McpSdk\McpSdkAdapter;
use Mcp\Server;
use Mcp\Server\Builder;

/**
 * Where a test hands the server to AgentCat (D76): the Builder before build(), the Builder after build(), or the built
 * server. The mcp/sdk scenario suites run once for each, through a subclass per entry. A test calls `track()` where a
 * customer calls `AgentCat::track()` before build(); for the other entries, `build()` tracks the server right after
 * build(), with the same state.
 */
enum Entry: string
{
    case BeforeBuild = 'before build()';
    case BuilderAfterBuild = 'the Builder after build()';
    case BuiltServer = 'the built server';

    /** Tracks `$builder` now (before build()) or keeps `$server` until `build()`. */
    public function track(Builder $builder, TrackedServer $server, Publisher $publisher): void
    {
        if ($this === self::BeforeBuild) {
            McpSdkAdapter::install($builder, $server, $publisher, new CallInterceptor($publisher));

            return;
        }
        Pending::put($builder, $server, $publisher);
    }

    /** Builds the server, and tracks it after build() when `track()` kept it. */
    public function build(Builder $builder): Server
    {
        $built = $builder->build();
        $pending = Pending::take($builder);
        if ($pending !== null) {
            [$server, $publisher] = $pending;
            McpSdkAdapter::install($this === self::BuiltServer ? $built : $builder, $server, $publisher, new CallInterceptor($publisher));
        }

        return $built;
    }

    /** True for the entries whose request handlers AgentCat cannot precede (after build()). */
    public function afterBuild(): bool
    {
        return $this !== self::BeforeBuild;
    }
}
