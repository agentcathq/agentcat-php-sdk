<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Logging\Log;
use PHPUnit\Framework\Assert;

/**
 * The recording side of the adapter fixtures: a queue that records what it delivers, its publisher, and the SDK log
 * routed to `$logger`. Constructing the fixture routes the log; the test calls `Log::reset()` in `tearDown()`.
 */
trait RecordsEvents
{
    public const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public readonly MemoryLogger $logger;

    public readonly RecordingTransport $transport;

    public readonly RecordingExporter $exporter;

    public readonly EventQueue $queue;

    public readonly Publisher $publisher;

    public function __construct()
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: $this->transport, exporters: $this->exporter);
        $this->publisher = new Publisher($this->queue);
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
