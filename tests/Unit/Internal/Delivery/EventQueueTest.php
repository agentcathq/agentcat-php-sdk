<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ExporterSink;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Pipeline\Sanitizer;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class EventQueueTest extends TestCase
{
    private const string GAVE_UP = 'Failed to deliver AgentCat event after retries: ';

    /** Read by `ApiBaseUrl::resolve()` through `getenv()`, `$_ENV` and `$_SERVER`. */
    private const array URL_VARIABLES = ['AGENTCAT_API_URL', 'MCPCAT_API_URL'];

    private MemoryLogger $logger;

    /** @var array<string, array{getenv: string|false, env: mixed, server: mixed}> */
    private array $savedEnvironment = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        foreach (self::URL_VARIABLES as $name) {
            $this->savedEnvironment[$name] = ['getenv' => getenv($name), 'env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $saved) {
            putenv($saved['getenv'] === false ? $name : $name . '=' . $saved['getenv']);
            unset($_ENV[$name], $_SERVER[$name]);
            if ($saved['env'] !== null) {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['server'] !== null) {
                $_SERVER[$name] = $saved['server'];
            }
        }
        EventQueue::setGlobal(null);
        Log::reset();
    }

    #[Group('ac-03-1')]
    public function testAddOnlyStoresTheEvent(): void
    {
        $called = [];
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $queued = new QueuedEvent(
            $this->event(),
            static function (Event $event) use (&$called): Event {
                $called[] = 'redactEvent';

                return $event;
            },
            null,
            [
                static function (Event $event) use (&$called): void {
                    $called[] = 'pending';
                },
            ],
        );

        $queue->add($queued);

        $this->assertSame(['queueLength' => 1, 'activeRequests' => 0], $queue->stats());
        $this->assertSame([], $transport->deliveries);
        $this->assertSame([], $called);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-03-2')]
    #[MaximumDuration(3000)]
    public function testFullQueueDropsTheOldestWaitingEvent(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);

        for ($i = 0; $i < 10_001; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }

        $this->assertSame(10_000, $queue->stats()['queueLength']);
        $this->assertSame(['Event queue full, dropping oldest event'], $this->logger->messages());

        $queue->pump();

        $this->assertSame(['event-1', 'event-2', 'event-3', 'event-4', 'event-5'], $this->resourceNames($transport));
        $this->assertSame(['queueLength' => 9_995, 'activeRequests' => 5], $queue->stats());
    }

    #[Group('ac-03-9')]
    public function testAddAfterShutdownDropsTheEvent(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $queue->shutdown();

        $queue->add(new QueuedEvent($this->event()));

        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
        $this->assertSame(0, $queue->stats()['queueLength']);
        $this->assertTrue($queue->flush());
        $this->assertSame([], $transport->deliveries);
    }

    #[Group('ac-03-13')]
    public function testFailingRegistrarKeepsTheEvent(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport, onEnqueue: static fn(): never => throw new \RuntimeException('nope'));

        $queue->add(new QueuedEvent($this->event()));

        $this->assertSame(['Failed to register AgentCat event delivery as a background task: RuntimeException: nope'], $this->logger->messages());
        $this->assertSame(1, $queue->stats()['queueLength']);
        $this->assertTrue($queue->flush());
        $this->assertCount(1, $transport->deliveries);
    }

    #[Group('ac-03-13')]
    public function testRegistrarRunsOnEveryAddTelemetryOnlyIncluded(): void
    {
        $calls = 0;
        $queue = new EventQueue(transport: new RecordingTransport(), onEnqueue: static function () use (&$calls): void {
            ++$calls;
        });

        $queue->add(new QueuedEvent($this->event()));
        $queue->add(new QueuedEvent($this->event(projectId: null)));
        $queue->add(new QueuedEvent($this->event(projectId: '')));

        $this->assertSame(3, $calls);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-03-7')]
    public function testEventWithoutProjectIdGoesToTheExportersOnly(): void
    {
        $transport = new RecordingTransport();
        $exporter = new RecordingExporter();
        $queue = new EventQueue(transport: $transport, exporters: $exporter);
        $withoutId = $this->event(projectId: null);
        $emptyId = $this->event(projectId: '');

        $queue->add(new QueuedEvent($withoutId));
        $queue->add(new QueuedEvent($emptyId));

        $this->assertTrue($queue->flush());
        $this->assertSame([], $transport->deliveries);
        $this->assertSame([$withoutId, $emptyId], $exporter->events);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-05-P6')]
    public function testExportersReceiveTheProcessedInstance(): void
    {
        $exporter = new RecordingExporter();
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $exporter);
        $event = $this->event(str_repeat('r', 300));
        $event->userIntent = 'a secret plan';
        $event->response = Json::decode('{"content":[{"type":"image","data":"iVBOR","mimeType":"image/png"}]}');

        $queue->add(new QueuedEvent($event, null, static fn(string $s): string => str_replace('secret', '[REDACTED]', $s)));

        $this->assertTrue($queue->flush());
        $this->assertCount(1, $exporter->events);
        $this->assertSame($event, $exporter->events[0]);
        $this->assertSame('a [REDACTED] plan', $event->userIntent);
        $this->assertSame(Sanitizer::IMAGE_MARKER, JsonAssert::at($event->response, 'content', 0, 'text'));
        $this->assertSame(259, strlen((string) $event->resourceName));
    }

    #[Group('ac-05-P6')]
    public function testEventDroppedByRedactEventReachesNothing(): void
    {
        $transport = new RecordingTransport();
        $exporter = new RecordingExporter();
        $queue = new EventQueue(transport: $transport, exporters: $exporter);

        $queue->add(new QueuedEvent($this->event(), static fn(Event $event): ?Event => null));
        $queue->add(new QueuedEvent($this->event(projectId: null), static fn(Event $event): ?Event => null));

        $this->assertTrue($queue->flush());
        $this->assertSame([], $transport->deliveries);
        $this->assertSame([], $exporter->events);
        $this->assertSame(['Event dropped by redactEvent hook', 'Event dropped by redactEvent hook'], $this->logger->messages());
    }

    #[Group('ac-03-23')]
    public function testDeliveryCarriesTheEncodedProcessedEvent(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $queue->configure('https://ingest.example.test/');
        $event = $this->event();

        $queue->add(new QueuedEvent($event));
        $queue->pump();

        $this->assertCount(1, $transport->deliveries);
        $delivery = $transport->deliveries[0];
        $this->assertSame('https://ingest.example.test/v1/public/events/', $delivery->url);
        $this->assertSame(WireEncoder::encode($event), $delivery->body);
        $this->assertSame($event->id, $delivery->eventId);
        $this->assertMatchesRegularExpression('/\Aevt_[0-9A-Za-z]{27}\z/', $delivery->eventId);
        $this->assertSame(Delivery::forEvent($event, $delivery->url, $delivery->body)->summary, $delivery->summary);
    }

    public function testEndpointDefaultsToTheAgentCatApiAndTheLastConfigureWins(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);

        $queue->add(new QueuedEvent($this->event()));
        $queue->pump();
        $queue->configure('https://first.example.test');
        $queue->configure('https://second.example.test');
        $queue->add(new QueuedEvent($this->event()));
        $queue->pump();

        $this->assertSame(
            ['https://api.agentcat.com/v1/public/events/', 'https://second.example.test/v1/public/events/'],
            array_map(static fn(Delivery $delivery): string => $delivery->url, $transport->deliveries),
        );
    }

    #[Group('ac-07-3')]
    public function testEndpointBeforeConfigureComesFromTheEnvironment(): void
    {
        putenv('AGENTCAT_API_URL=https://env.example.test/');
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);

        $queue->add(new QueuedEvent($this->event()));
        $queue->pump();

        $this->assertCount(1, $transport->deliveries);
        $this->assertSame('https://env.example.test/v1/public/events/', $transport->deliveries[0]->url);
    }

    public function testExporterSinkCanBeReplaced(): void
    {
        $first = new RecordingExporter();
        $second = new RecordingExporter();
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $first);

        $queue->setExporterSink($second);
        $queue->add(new QueuedEvent($this->event(projectId: null)));

        $this->assertTrue($queue->flush());
        $this->assertSame([], $first->events);
        $this->assertCount(1, $second->events);
    }

    public function testExporterRunsBeforeEveryAttempt(): void
    {
        $transport = new RecordingTransport(complete: false);
        $exporter = new RecordingExporter();
        $queue = new EventQueue(transport: $transport, exporters: $exporter);
        $event = $this->event();

        $queue->add(new QueuedEvent($event));
        $queue->pump();

        $this->assertSame([], $exporter->events);
        $this->assertCount(1, $transport->deliveries);
        $beforeAttempt = $transport->deliveries[0]->beforeAttempt;
        $this->assertInstanceOf(\Closure::class, $beforeAttempt);
        $beforeAttempt();
        $beforeAttempt();
        $this->assertSame([$event, $event], $exporter->events);
    }

    public function testThrowingExporterDoesNotStopDelivery(): void
    {
        $transport = new RecordingTransport();
        $exporter = new RecordingExporter(new \RuntimeException('boom'));
        $queue = new EventQueue(transport: $transport, exporters: $exporter);
        $first = $this->event('first');

        $queue->add(new QueuedEvent($first));

        $this->assertTrue($queue->flush());
        $this->assertCount(1, $transport->deliveries);
        $this->assertSame([$first], $exporter->events);
        $this->assertSame(['Telemetry export error: RuntimeException: boom'], $this->logger->messages());

        $exporter->failure = null;
        $second = $this->event('second');
        $queue->add(new QueuedEvent($second));

        $this->assertTrue($queue->flush());
        $this->assertSame(['first', 'second'], $this->resourceNames($transport));
        $this->assertSame([$first, $second], $exporter->events);
        $this->assertSame(['Telemetry export error: RuntimeException: boom'], $this->logger->messages());
    }

    public function testThrowingExporterForTelemetryOnlyEventIsLogged(): void
    {
        $exporter = new RecordingExporter(new \RuntimeException('boom'));
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $exporter);

        $queue->add(new QueuedEvent($this->event(projectId: null)));

        $this->assertTrue($queue->flush());
        $this->assertCount(1, $exporter->events);
        $this->assertSame(['Telemetry export error: RuntimeException: boom'], $this->logger->messages());
    }

    public function testEncodeFailureExportsOnceAndSendsNothing(): void
    {
        $transport = new RecordingTransport();
        $exporter = new RecordingExporter();
        $queue = new EventQueue(
            transport: $transport,
            exporters: $exporter,
            encode: static fn(Event $event): never => throw new \RuntimeException('cannot encode'),
        );
        $event = $this->event();

        $queue->add(new QueuedEvent($event));
        $queue->pump();

        $this->assertSame([], $transport->deliveries);
        $this->assertSame([$event], $exporter->events);
        $this->assertSame(['Failed to encode event ' . $event->id . ': RuntimeException: cannot encode'], $this->logger->messages());
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats());
    }

    public function testEncodeFailureLogsAfterTheExporterError(): void
    {
        $queue = new EventQueue(
            transport: new RecordingTransport(),
            exporters: new RecordingExporter(new \RuntimeException('boom')),
            encode: static fn(Event $event): never => throw new \RuntimeException('cannot encode'),
        );
        $event = $this->event();

        $queue->add(new QueuedEvent($event));
        $queue->pump();

        $this->assertSame(
            ['Telemetry export error: RuntimeException: boom', 'Failed to encode event ' . $event->id . ': RuntimeException: cannot encode'],
            $this->logger->messages(),
        );
    }

    public function testCustomEncoderBuildsTheBody(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport, encode: static fn(Event $event): string => '{"custom":"' . $event->resourceName . '"}');

        $queue->add(new QueuedEvent($this->event('marker')));
        $queue->pump();

        $this->assertCount(1, $transport->deliveries);
        $this->assertSame('{"custom":"marker"}', $transport->deliveries[0]->body);
    }

    public function testScalarFreeFormFieldIsLoggedAndTheDeliveryStillStarts(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $event = $this->event();
        $event->response = 'ok';
        $event->properties = 7;

        $queue->add(new QueuedEvent($event));
        $queue->pump();

        $this->assertCount(1, $transport->deliveries);
        $this->assertSame(
            [
                ['level' => LogLevel::WARNING, 'message' => 'WARN: Event ' . $event->id . ': response is not a JSON object or array and was not sent'],
                ['level' => LogLevel::WARNING, 'message' => 'WARN: Event ' . $event->id . ': properties is not a JSON object or array and was not sent'],
            ],
            $this->logger->records,
        );
    }

    public function testFlushReportsWhetherEverythingCompleted(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 12; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }

        $this->assertTrue($queue->flush(1.0));
        $this->assertCount(12, $transport->deliveries);
        $this->assertSame(0, $transport->waits, 'freed slots are refilled without waiting');
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats());
    }

    public function testFlushOfAnEmptyQueueIsTrue(): void
    {
        $this->assertTrue((new EventQueue(transport: new RecordingTransport(complete: false)))->flush(0.0));
    }

    public function testFlushStopsAtItsTimeout(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event()));

        $this->assertFalse($queue->flush(0.0));
        $this->assertSame(0, $transport->waits);
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 1], $queue->stats());

        $this->assertFalse($queue->flush(2.5));
        $this->assertSame(1, $transport->waits);
        $this->assertEqualsWithDelta(2.5, $transport->now(), PHP_FLOAT_EPSILON);
    }

    public function testFlushWithoutTimeoutHasNoDeadline(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event()));

        $this->assertFalse($queue->flush(), 'the recording clock jumps to an INF deadline');
        $this->assertSame(1, $transport->waits);
        $this->assertSame(INF, $transport->now());
    }

    public function testFlushWithANegativeOrNanTimeoutReturnsAtOnce(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event()));

        $this->assertFalse($queue->flush(-1.0));
        $this->assertFalse($queue->flush(NAN));
        $this->assertSame(0, $transport->waits);
        $this->assertSame([], $this->logger->messages());
        $this->assertCount(1, $transport->deliveries);
    }

    public function testFlushWaitsWhileAllSlotsAreBusy(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }

        $this->assertFalse($queue->flush(1.0));
        $this->assertCount(5, $transport->deliveries);
        $this->assertSame(1, $transport->waits);
    }

    #[Group('ac-03-9')]
    public function testShutdownReportsEventsStillWaiting(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }

        $queue->shutdown();

        $this->assertEqualsWithDelta(5.0, EventQueue::SHUTDOWN_TIMEOUT_SECONDS, PHP_FLOAT_EPSILON);
        $this->assertCount(5, $transport->deliveries);
        $this->assertEqualsWithDelta(5.0, $transport->now(), PHP_FLOAT_EPSILON, 'the drain budget');
        $this->assertSame(['Shutting down with 2 events still in queue'], $this->logger->messages());
    }

    #[Group('ac-03-9')]
    public function testShutdownUsesItsTimeout(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport, shutdownTimeoutSeconds: 0.25);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();

        $this->assertEqualsWithDelta(0.25, $transport->now(), PHP_FLOAT_EPSILON);
        $this->assertSame([], $this->logger->messages(), 'in-flight deliveries are not reported');
    }

    /** A signal drain, the shutdown function after it, and a signal during that flush share one budget. */
    #[Group('ac-03-9')]
    public function testLaterShutdownsShareTheFirstShutdownsBudget(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 6; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }

        $queue->shutdown();
        $queue->finalShutdown();
        $queue->shutdown();

        $this->assertEqualsWithDelta(5.0, $transport->now(), PHP_FLOAT_EPSILON, 'one budget in total');
        $this->assertSame(1, $transport->waits);
        $this->assertSame(array_fill(0, 3, 'Shutting down with 1 events still in queue'), $this->logger->messages());
    }

    public function testShutdownDeliversEverything(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();

        $this->assertCount(1, $transport->deliveries);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-05-P3')]
    public function testFailingHookNeverReachesTheCaller(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event(), static fn(Event $event): never => throw new \RuntimeException('hook')));
        $queue->add(new QueuedEvent($this->event('kept')));

        $queue->pump();

        $this->assertTrue($queue->flush());
        $this->assertSame(['kept'], $this->resourceNames($transport));
        $this->assertSame(['Failed to redact event (event-level hook): RuntimeException: hook'], $this->logger->messages());
    }

    #[Group('ac-07-13')]
    public function testFailingTransportNeverReachesTheCaller(): void
    {
        $transport = new RecordingTransport(failure: new \RuntimeException('transport down'));
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event()));
        $queue->add(new QueuedEvent($this->event()));

        $queue->pump();

        $this->assertSame([self::GAVE_UP . 'RuntimeException: transport down'], $this->logger->messages());

        $this->assertFalse($queue->flush());
        $this->assertSame(array_fill(0, 3, self::GAVE_UP . 'RuntimeException: transport down'), $this->logger->messages());
        $this->assertSame([], $transport->deliveries);
    }

    public function testReentrantCallsFromAnExporterDoNothing(): void
    {
        $transport = new RecordingTransport();
        $exporter = new class implements ExporterSink {
            public ?EventQueue $queue = null;

            /** @var list<bool> */
            public array $results = [];

            #[\Override]
            public function export(Event $event): void
            {
                $this->queue?->pump();
                $this->results[] = $this->queue?->flush() ?? true;
            }
        };
        $queue = new EventQueue(transport: $transport, exporters: $exporter);
        $exporter->queue = $queue;
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }

        $queue->pump();

        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4'], $this->resourceNames($transport));
        $this->assertSame(['queueLength' => 2, 'activeRequests' => 0], $queue->stats());
        $this->assertSame(array_fill(0, 5, false), $exporter->results);

        $this->assertTrue($queue->flush());
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'], $this->resourceNames($transport));
        $this->assertSame(array_fill(0, 7, false), $exporter->results);
    }

    public function testFlushAndPumpWorkAgainAfterAFailure(): void
    {
        $transport = new RecordingTransport(failure: new \RuntimeException('transport down'));
        $queue = new EventQueue(transport: $transport);

        $this->assertFalse($queue->flush());
        $queue->add(new QueuedEvent($this->event()));
        $queue->pump();
        $transport->failure = null;

        $this->assertTrue($queue->flush());
        $this->assertCount(1, $transport->deliveries);
    }

    public function testTelemetryOnlyEventWithoutExportersGoesNowhere(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);

        $queue->add(new QueuedEvent($this->event(projectId: null)));

        $this->assertTrue($queue->flush());
        $this->assertSame([], $transport->deliveries);
        $this->assertSame([], $this->logger->messages());
    }

    public function testGlobalIsLazyAndReplaceable(): void
    {
        $first = EventQueue::global();

        $this->assertSame($first, EventQueue::global());

        EventQueue::setGlobal(null);
        $second = EventQueue::global();

        $this->assertNotSame($first, $second);

        $mine = new EventQueue(transport: new RecordingTransport());
        EventQueue::setGlobal($mine);

        $this->assertSame($mine, EventQueue::global());
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $second->stats());
    }

    public function testStatsReportsWaitingAndInFlight(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(new EventProcessor(), $transport);
        for ($i = 0; $i < 6; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }

        $this->assertSame(['queueLength' => 6, 'activeRequests' => 0], $queue->stats());

        $queue->pump();

        $this->assertSame(['queueLength' => 1, 'activeRequests' => 5], $queue->stats());
    }

    private function event(string $resourceName = 'tool', ?string $projectId = 'proj_test'): Event
    {
        return new Event(projectId: $projectId, eventType: 'mcp:tools/call', resourceName: $resourceName);
    }

    /** @return list<mixed> the `resource_name` of every delivery body */
    private function resourceNames(RecordingTransport $transport): array
    {
        return array_map(
            static fn(Delivery $delivery): mixed => JsonAssert::at(Json::decode($delivery->body), 'resource_name'),
            $transport->deliveries,
        );
    }
}
