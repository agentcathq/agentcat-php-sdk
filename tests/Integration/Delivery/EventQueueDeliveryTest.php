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
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
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

    /**
     * Review Focus 1: an exporter endpoint that hangs, on a server whose AgentCat API works. `pump()` returns at once,
     * the event is delivered, and `shutdown()` waits for the export only until its budget, then abandons it.
     */
    #[Group('ac-06-63')]
    #[MaximumDuration(5000)]
    public function testASlowExportEndpointNeverHoldsTheEventsBack(): void
    {
        $events = $this->collector()->scenario();
        $slow = $this->collector()->scenario([], ['status' => 200, 'delayMs' => 3000]);
        $channel = new CurlHttpChannel();
        $exporter = new readonly class ($channel, $slow) implements ExporterSink {
            public function __construct(private HttpChannel $channel, private string $url) {}

            #[\Override]
            public function export(Event $event): void
            {
                $this->channel->post(new HttpRequest($this->url . '/v1/traces', [], '{}'), static function (): void {});
            }
        };
        $queue = new EventQueue(new EventProcessor(), new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04])), $exporter, null, 0.3, null, $channel);
        $queue->configure($events);
        $queue->add(new QueuedEvent($this->event('slow-export')));

        $start = hrtime(true);
        $queue->pump();
        $this->assertLessThan(1.0, (hrtime(true) - $start) / 1e9, 'pump() at handler entry never waits');

        $start = hrtime(true);
        $queue->shutdown();
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertGreaterThanOrEqual(0.29, $elapsed, 'shutdown() waits for the export, up to its budget');
        $this->assertLessThan(1.5, $elapsed, 'the shutdown budget (0.3 s here) bounds the slow export');
        $this->assertCount(1, $this->collector()->requests($events), 'the event is delivered');
        $this->assertSame(1, $channel->pending(), 'the slow export is abandoned');
    }

    /**
     * I2: a SIGTERM drain that runs inside a callback of the side channel (the seam stands in for the signal
     * handler). It delivers the event on the transport's own multi handle and returns once the event is delivered: the
     * side channel cannot progress inside its own callback, so the drain does not wait for it. The side transfer then
     * completes in the next passes.
     */
    #[MaximumDuration(3000)]
    public function testADrainInsideASideTransferCallbackDeliversTheEventAndReturns(): void
    {
        $events = $this->collector()->scenario();
        $side = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 200_000)]);
        $state = new class {
            public ?EventQueue $queue = null;

            /** Seconds the drain took; null until it ran. */
            public ?float $drained = null;
        };
        $channel = new CurlHttpChannel(onCallback: function () use ($state): void {
            if ($state->drained !== null || !$state->queue instanceof EventQueue) {
                return;
            }
            $state->queue->add(new QueuedEvent($this->event('in-callback')));
            $begin = hrtime(true);
            $state->queue->finalShutdown();
            $state->drained = (hrtime(true) - $begin) / 1e9;
        });
        $state->queue = new EventQueue(new EventProcessor(), new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04])), new NullExporterSink(), null, EventQueue::SHUTDOWN_TIMEOUT_SECONDS, null, $channel);
        $state->queue->configure($events);
        $outcome = null;
        $channel->post(new HttpRequest($side . '/v1/traces', [], '{}'), static function (HttpResponse $response) use (&$outcome): void {
            $outcome = [$response->status, $response->error];
        });

        $until = microtime(true) + 2.0;
        do {
            $this->assertLessThan($until, microtime(true), 'the side transfer did not finish');
            $channel->pump();
            $channel->waitForActivity(0.05);
        } while ($channel->pending() > 0);

        $this->assertIsFloat($state->drained, 'the drain ran inside a write callback');
        $this->assertLessThan(2.5, $state->drained, 'it did not wait for the side channel it interrupted (the budget is 5 s)');
        $this->assertCount(1, $this->collector()->requests($events), "the event was delivered on the transport's own handle");
        $this->assertSame([200, null], $outcome, 'the side transfer completed');
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
