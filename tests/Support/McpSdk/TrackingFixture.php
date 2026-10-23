<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
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

    /** @param Entry $entry where the tests hand the server to AgentCat */
    public function __construct(public Entry $entry = Entry::BeforeBuild)
    {
        // RecordsEvents's constructor, which this one replaces.
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: $this->transport, exporters: $this->exporter);
        $this->publisher = new Publisher($this->queue);
    }

    /** A Builder for the server `harness-server` 1.2.3. */
    public static function builder(): Builder
    {
        return Server::builder()->setServerInfo('harness-server', '1.2.3');
    }

    /** Tracks the Builder (`Entry::BeforeBuild`), or the Builder or its server once `harness()` builds it. */
    public function track(Builder $builder, Options $options = new Options(), string $projectId = self::PROJECT): TrackedServer
    {
        $server = new TrackedServer($projectId, $options, McpSdkAdapter::serverInfo($builder), $this->publisher);
        $this->entry->track($builder, $server, $this->publisher);

        return $server;
    }

    /** Builds the server, tracks it after build() for the other entries, and runs the handshake as `harness-client` 1.0.0. */
    public function harness(Builder $builder): HttpHarness
    {
        $harness = new HttpHarness($this->entry->build($builder));
        $harness->initialize();

        return $harness;
    }
}
