<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use Mcp\Server\Builder;

/** The Builders that a test tracks after build(): `Entry::build()` installs their state once. */
final class Pending
{
    /** @var \WeakMap<Builder, array{TrackedServer, Publisher}>|null */
    private static ?\WeakMap $builders = null;

    public static function put(Builder $builder, TrackedServer $server, Publisher $publisher): void
    {
        self::$builders ??= new \WeakMap();
        self::$builders[$builder] = [$server, $publisher];
    }

    /** @return array{TrackedServer, Publisher}|null */
    public static function take(Builder $builder): ?array
    {
        $pending = self::$builders[$builder] ?? null;
        if ($pending !== null) {
            unset(self::$builders[$builder]);
        }

        return $pending;
    }
}
