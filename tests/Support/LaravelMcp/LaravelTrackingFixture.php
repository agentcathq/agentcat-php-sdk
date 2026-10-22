<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\RecordsEvents;
use Laravel\Mcp\Server;

/**
 * Tracks laravel/mcp servers the way `track()` does, into a queue that records what it delivers. Constructing it
 * routes the SDK log to `$logger`; `LaravelTestCase` calls `Log::reset()` in `tearDown()`.
 */
final readonly class LaravelTrackingFixture
{
    use RecordsEvents;

    public function track(Server $server, Options $options = new Options(), string $projectId = self::PROJECT): TrackedServer
    {
        $state = new TrackedServer($projectId, $options, LaravelMcpAdapter::serverInfo($server), $this->publisher);
        LaravelMcpAdapter::install($server, $state, $this->publisher, new CallInterceptor($this->publisher));

        return $state;
    }

    /** @return \Closure(Server): void that tracks each server of a harness with these options */
    public function tracker(Options $options = new Options()): \Closure
    {
        return function (Server $server) use ($options): void {
            $this->track($server, $options);
        };
    }
}
