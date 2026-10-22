<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ExporterSink;
use AgentCat\Internal\Delivery\NullExporterSink;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\RetryPolicy;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class EventQueueDeliveryTest extends TestCase
{
    /** Seconds; every flush in these tests has a deadline. */
    private const float DEADLINE = 8.0;

    private static ?MockCollector $collector = null;

    private MemoryLogger $logger;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        EventQueue::setGlobal(null);
        Log::reset();
    }

    #[Group('ac-03-1')]
    #[MaximumDuration(2000)]
    public function testEachEventIsOneJsonPost(): void
    {
        $base = $this->collector()->scenario();
        $queue = $this->queue($base);
        $events = [$this->event('a'), $this->event('b'), $this->event('c')];
        foreach ($events as $event) {
            $queue->add(new QueuedEvent($event));
        }

        $this->assertTrue($queue->flush(self::DEADLINE));

        $requests = $this->collector()->requests($base);
        $this->assertCount(3, $requests);
        $ids = [];
        foreach ($requests as $request) {
            $this->assertSame('POST', $request->method);
            $this->assertSame('/v1/public/events/', $request->path);
            $this->assertSame('application/json', $this->header($request, 'Content-Type'));
            $ids[] = JsonAssert::at($this->body($request), 'id');
        }
        $expected = array_map(static fn(Event $event): string => $event->id, $events);
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertCount(3, array_unique($ids));
    }

    #[Group('ac-03-3')]
    #[MaximumDuration(10000)]
    public function testAtMostFiveEventsAreSentAtOnce(): void
    {
        $base = $this->collector()->scenario([], ['status' => 202, 'delayMs' => 300, 'body' => '{"status":"accepted"}']);
        $queue = $this->queue($base);
        for ($i = 0; $i < 20; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }

        $this->assertTrue($queue->flush(self::DEADLINE));

        $requests = $this->collector()->requests($base);
        $this->assertCount(20, $requests);
        $concurrency = MockCollector::maxConcurrency($requests);
        $this->assertLessThanOrEqual(5, $concurrency);
        $this->assertGreaterThanOrEqual(2, $concurrency);
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats());
    }

    #[Group('ac-03-6')]
    #[MaximumDuration(2000)]
    public function testExportersRunAgainOnEveryRetry(): void
    {
        // D15 keeps TypeScript bug B03: the exporters receive the event once per API attempt.
        $base = $this->collector()->scenario([['status' => 503, 'body' => ''], ['status' => 503, 'body' => '']]);
        $exporter = new RecordingExporter();
        $queue = $this->queue($base, $exporter);
        $event = $this->event('retried');
        $queue->add(new QueuedEvent($event));

        $this->assertTrue($queue->flush(self::DEADLINE));

        $this->assertSame([$event, $event, $event], $exporter->events);
        $requests = $this->collector()->requests($base);
        $this->assertCount(3, $requests);
        foreach ($requests as $request) {
            $this->assertSame($event->id, JsonAssert::at($this->body($request), 'id'));
        }
    }

    #[Group('ac-03-9')]
    #[MaximumDuration(2000)]
    public function testShutdownStopsWaitingAtItsBudget(): void
    {
        $base = $this->collector()->scenario([], ['status' => 202, 'delayMs' => 3000, 'body' => '']);
        $queue = $this->queue($base, shutdownTimeoutSeconds: 0.3);
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event('slow-' . $i)));
        }
        $started = hrtime(true);

        $queue->shutdown();

        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual(0.29, $elapsed);
        $this->assertLessThan(1.5, $elapsed);
        $this->assertSame(['Shutting down with 2 events still in queue'], $this->logger->messages());
        $this->assertSame(['queueLength' => 2, 'activeRequests' => 5], $queue->stats());
        $this->assertEqualsWithDelta(5.0, EventQueue::SHUTDOWN_TIMEOUT_SECONDS, PHP_FLOAT_EPSILON);
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }

    private function queue(string $baseUrl, ExporterSink $exporters = new NullExporterSink(), float $shutdownTimeoutSeconds = EventQueue::SHUTDOWN_TIMEOUT_SECONDS): EventQueue
    {
        $queue = new EventQueue(
            new EventProcessor(),
            new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04])),
            $exporters,
            null,
            $shutdownTimeoutSeconds,
        );
        $queue->configure($baseUrl);

        return $queue;
    }

    private function event(string $resourceName): Event
    {
        return new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: $resourceName);
    }

    private function body(\stdClass $request): mixed
    {
        $this->assertIsString($request->body);

        return Json::decode($request->body);
    }

    private function header(\stdClass $request, string $name): ?string
    {
        $headers = $request->headers ?? null;
        $this->assertInstanceOf(\stdClass::class, $headers);
        foreach (get_object_vars($headers) as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $this->assertIsString($value);

                return $value;
            }
        }

        return null;
    }
}
