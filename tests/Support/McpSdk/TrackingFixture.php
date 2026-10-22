<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\RecordsEvents;
use Mcp\Server;
use Mcp\Server\Builder;

/**
 * Tracks mcp/sdk Builders the way `track()` does, into a queue that records what it delivers. Constructing it routes
 * the SDK log to `$logger`; the test calls `Log::reset()` in `tearDown()`.
 */
final readonly class TrackingFixture
{
    use RecordsEvents;

    /** A Builder for the server `harness-server` 1.2.3. */
    public static function builder(): Builder
    {
        return Server::builder()->setServerInfo('harness-server', '1.2.3');
    }

    public function track(Builder $builder, Options $options = new Options(), string $projectId = self::PROJECT): TrackedServer
    {
        $server = new TrackedServer($projectId, $options, McpSdkAdapter::serverInfo($builder), $this->publisher);
        McpSdkAdapter::install($builder, $server, $this->publisher, new CallInterceptor($this->publisher));

        return $server;
    }

    /** Builds the server and runs the handshake as `harness-client` 1.0.0. */
    public function harness(Builder $builder): HttpHarness
    {
        $harness = new HttpHarness($builder->build());
        $harness->initialize();

        return $harness;
    }
}
