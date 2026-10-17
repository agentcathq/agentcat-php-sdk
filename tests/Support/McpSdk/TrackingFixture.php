<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\Assert;

/**
 * Tracks mcp/sdk Builders the way `track()` does, into a queue that records what it delivers. Constructing it routes
 * the SDK log to `$logger`; the test calls `Log::reset()` in `tearDown()`.
 */
final readonly class TrackingFixture
{
    public const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public MemoryLogger $logger;

    public RecordingTransport $transport;

    public RecordingExporter $exporter;

    public EventQueue $queue;

    public Publisher $publisher;

    public function __construct()
    {
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

    public function track(Builder $builder, Options $options = new Options(), string $projectId = self::PROJECT): TrackedServer
    {
        $server = new TrackedServer($projectId, $options, McpSdkAdapter::serverInfo($builder));
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

    /**
     * Delivers everything queued, then returns every exported event, in order.
     *
     * @return list<Event>
     */
    public function events(): array
    {
        Assert::assertTrue($this->queue->flush(), 'flush');

        return $this->exporter->events;
    }

    /** Delivers everything queued and returns the event at `$index`; fails unless exactly `$of` events were delivered. */
    public function event(int $index = 0, int $of = 1): Event
    {
        $events = $this->events();
        Assert::assertCount($of, $events);
        $event = $events[$index] ?? null;
        Assert::assertInstanceOf(Event::class, $event);

        return $event;
    }

    /**
     * The log lines, in order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->logger->messages();
    }
}
