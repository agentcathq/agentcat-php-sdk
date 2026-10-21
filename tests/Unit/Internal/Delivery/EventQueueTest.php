<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ExporterSink;
use AgentCat\Internal\Delivery\SignalDrain;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Pipeline\Sanitizer;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\EventApiGuard;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingCompanion;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class EventQueueTest extends TestCase
{
    private const string GAVE_UP = 'Failed to deliver AgentCat event after retries: ';

    /** How long a slow hook takes on the simulated clock of `RecordingTransport`. */
    private const float HOOK_SECONDS = 0.01;

    /** The most events a self-feeding hook queues in a test: without a bound a broken queue would hang the test. */
    private const int SELF_FEEDING_BOUND = 1_000;

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
        EventApiGuard::set(); // tests/bootstrap.php's closed event API again, after the clear
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
        EventApiGuard::remove(); // the default URL: no variable at all; tearDown() puts the guard back
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

    /**
     * A worker's request-end wait is a `flush()` with a window: before it returns, every waiting event's hooks run, also
     * with every slot busy, so that a hook reads its own request (an Octane worker serves the next one in the process).
     */
    #[Group('ac-03-1')]
    public function testFlushRunsTheHooksOfEveryWaitingEventWhateverTheFreeSlots(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        for ($i = 0; $i < 7; ++$i) {
            $queue->add($this->recorded('event-' . $i, $ran));
        }

        $this->assertFalse($queue->flush(1.0));

        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'], $ran->getArrayCopy());
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4'], $this->resourceNames($transport));
        $this->assertSame(['queueLength' => 2, 'activeRequests' => 5], $queue->stats());
        $this->assertSame(1, $transport->waits, 'the window: the API did not answer');
        $transport->complete = true;
        $transport->pump(); // the API answers: the slots are free, and two payloads still wait

        $this->assertSame(['queueLength' => 2, 'activeRequests' => 0], $queue->stats());
        $this->assertTrue($queue->hasWork());
        $this->assertTrue($queue->flush(1.0));
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'], $this->resourceNames($transport));
        $this->assertCount(7, $ran, 'each hook ran once');
    }

    /** Outside `flush()` an event's hooks still run when it gets a slot: a pump runs none for an event that waits. */
    public function testPumpRunsNoHookOfAnEventThatFindsNoFreeSlot(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        for ($i = 0; $i < 7; ++$i) {
            $queue->add($this->recorded('event-' . $i, $ran));
        }

        $queue->pump();

        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4'], $ran->getArrayCopy());
        $this->assertSame(['queueLength' => 2, 'activeRequests' => 5], $queue->stats());
    }

    /**
     * A 0 s flush (a console command's request-end wait, `AgentCat::flush(0)`) stays one non-blocking pass. A
     * backlog's hooks wait for the shutdown function of the same request or process, inside its 5 s budget; the pass
     * runs only those of the events it starts. A negative or NaN timeout is no window either.
     *
     * @return iterable<string, array{float}>
     */
    public static function noWindow(): iterable
    {
        yield '0 s' => [0.0];
        yield 'negative' => [-1.0];
        yield 'NaN' => [NAN];
    }

    #[DataProvider('noWindow')]
    public function testAFlushWithoutAWindowRunsNoHookPastTheFreeSlots(float $timeout): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        for ($i = 0; $i < 1000; ++$i) {
            $queue->add($this->slow('event-' . $i, $ran, $transport));
        }

        $this->assertFalse($queue->flush($timeout));

        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4'], $ran->getArrayCopy());
        $this->assertSame(['queueLength' => 995, 'activeRequests' => 5], $queue->stats());
        $this->assertEqualsWithDelta(5 * self::HOOK_SECONDS, $transport->now(), 1e-9, 'only the hooks of the five started events ran');
    }

    /**
     * A hook that queues an event whose own hook queues the next one (a `redactEvent` hook that publishes a custom
     * event: custom events carry the same hook) keeps the queue busy, and the flush still ends at its deadline. Each
     * pass prepares the events that waited when it began, each once; later passes take the new ones.
     */
    public function testAFlushEndsAtItsDeadlineWhileAHookKeepsQueueing(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        $children = 0;
        $redact = null;
        $redact = function (Event $event) use (&$redact, &$children, $queue, $transport): Event {
            $transport->waitForActivity($transport->now() + self::HOOK_SECONDS); // the hook takes its time
            if ($children < 10_000) { // a bound for the test only: without it a broken queue would hang it
                ++$children;
                $queue->add(new QueuedEvent($this->event('child'), $redact));
            }

            return $event;
        };
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i), $redact, pending: [
                static function (Event $event) use ($ran): void {
                    $ran->append((string) $event->resourceName);
                },
            ]));
        }

        $this->assertFalse($queue->flush(1.0));

        $this->assertLessThan(1.0 + 1.0, $transport->now(), 'the deadline, plus the hooks of the last pass');
        $this->assertLessThan(100, $children);
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'], $ran->getArrayCopy(), 'each event that waited at the start, once');
    }

    /** An event that a hook queues during a pass waits for the next pass, after the window's wait. */
    public function testAPassPreparesOnlyTheEventsThatWaitedWhenItBegan(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $at = new \ArrayObject();
        for ($i = 0; $i < 5; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $queue->add(new QueuedEvent($this->event('parent'), pending: [
            function () use ($queue, $transport, $at): void {
                $at->append(['parent', $transport->now()]);
                $queue->add(new QueuedEvent($this->event('child'), pending: [
                    static function () use ($transport, $at): void {
                        $at->append(['child', $transport->now()]);
                    },
                ]));
            },
        ]));

        $this->assertFalse($queue->flush(1.0));

        $this->assertSame([['parent', 0.0], ['child', 1.0]], $at->getArrayCopy());
        $this->assertSame(['queueLength' => 2, 'activeRequests' => 5], $queue->stats());
    }

    /**
     * A telemetry-only event starts no transfer, so the slots stay free while a `redactEvent` hook queues one more on
     * every run (a hook that publishes a custom event). A pump, the handler-entry pump of a request included, starts
     * only the events queued when it began, each once; the next pump takes the new ones.
     */
    public function testAPumpStartsOnlyTheEventsQueuedWhenItBegan(): void
    {
        $exporter = new RecordingExporter();
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $exporter);
        $children = new \ArrayObject();
        $redact = $this->selfFeeding($queue, $children);
        for ($i = 0; $i < 3; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i, null), $redact));
        }

        $queue->pump();

        $this->assertCount(3, $children, 'the hook of each event queued at the start, once');
        $this->assertSame(['event-0', 'event-1', 'event-2'], array_map(static fn(Event $event): ?string => $event->resourceName, $exporter->events));
        $this->assertSame(['queueLength' => 3, 'activeRequests' => 0], $queue->stats());

        $queue->pump();

        $this->assertCount(6, $children);
        $this->assertSame(['event-0', 'event-1', 'event-2', 'child', 'child', 'child'], array_map(static fn(Event $event): ?string => $event->resourceName, $exporter->events));
    }

    /** The same hook, slow, in a flush: each pass starts only the events queued when it began, and the deadline ends it. */
    public function testAFlushEndsAtItsDeadlineWhileATelemetryOnlyHookKeepsQueueing(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $children = new \ArrayObject();
        $redact = $this->selfFeeding($queue, $children, $transport);
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i, null), $redact));
        }

        $delivered = $queue->flush(1.0);

        $this->assertLessThan(1.0 + 1.0, $transport->now(), 'the deadline, plus the hooks of the last pass');
        $this->assertLessThan(self::SELF_FEEDING_BOUND, count($children), 'the deadline ended the flush, not the bound of the test');
        $this->assertFalse($delivered);
    }

    /**
     * A SIGTERM during a hook, with an earlier handler that keeps the process running (ac-03-11): the signal drain
     * (`finalShutdown()`) delivers the whole queue in the middle of the pass. The pass then stops at the empty queue,
     * logs nothing, and still pumps the transport, so the event whose hook ran completes too.
     */
    public function testASignalDrainDuringAHookEndsThePumpPassCleanly(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent($this->event('event-0'), pending: [
            static function () use ($queue): void {
                $queue->finalShutdown();
            },
        ]));
        $queue->add(new QueuedEvent($this->event('event-1')));
        $queue->add(new QueuedEvent($this->event('event-2')));

        $queue->pump();

        $this->assertSame([], $this->logger->messages());
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats(), 'the pass pumped the transport');
        $names = $this->resourceNames($transport);
        sort($names);
        $this->assertSame(['event-0', 'event-1', 'event-2'], $names, 'each event delivered once');
    }

    /** The same drain during a hook of a flush's prepare pass: the pass stops at the empty queue, and the flush delivers. */
    public function testASignalDrainDuringAHookEndsThePreparePassCleanly(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 5; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $queue->add(new QueuedEvent($this->event('event-5'), pending: [
            static function () use ($queue, $transport): void {
                $transport->complete = true; // the API answers the five in flight, so the drain empties the queue
                $queue->finalShutdown();
            },
        ]));
        $queue->add(new QueuedEvent($this->event('event-6')));

        $delivered = $queue->flush(1.0);

        $this->assertSame([], $this->logger->messages());
        $this->assertTrue($delivered);
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats());
        $names = $this->resourceNames($transport);
        sort($names);
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'event-6'], $names, 'each event delivered once');
    }

    /** The process ends with `shutdown()`: it spends its budget on the events it can start, not on hooks of the others. */
    public function testShutdownRunsNoHookOfAnEventThatFindsNoFreeSlot(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        for ($i = 0; $i < 7; ++$i) {
            $queue->add($this->recorded('event-' . $i, $ran));
        }

        $queue->shutdown();

        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4'], $ran->getArrayCopy());
        $this->assertSame(['Shutting down with 2 events still in queue'], $this->logger->messages());
    }

    public function testEventsThatAFlushPreparedGoBeforeLaterOnes(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        for ($i = 0; $i < 6; ++$i) {
            $queue->add($this->recorded('event-' . $i, $ran));
        }
        $queue->flush(1.0);
        $queue->add($this->recorded('later', $ran));
        $transport->complete = true;

        $queue->pump(); // the API answers the five in flight
        $queue->pump();

        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'later'], $this->resourceNames($transport));
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'event-5', 'later'], $ran->getArrayCopy());
    }

    /** A hook that queues an event during a flush (a custom event): the flush runs that event's hooks too. */
    public function testFlushRunsTheHooksOfAnEventThatAHookQueued(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        $ran = new \ArrayObject();
        for ($i = 0; $i < 5; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $queue->add(new QueuedEvent($this->event('parent'), pending: [
            function () use ($queue, $ran): void {
                $ran->append('parent');
                $queue->add($this->recorded('child', $ran));
            },
        ]));

        $queue->flush(1.0);

        $this->assertSame(['parent', 'child'], $ran->getArrayCopy());
        $this->assertSame(['queueLength' => 2, 'activeRequests' => 5], $queue->stats());
    }

    /** The cap counts the prepared payloads too, and the oldest of them goes first. */
    #[Group('ac-03-2')]
    #[MaximumDuration(5000)]
    public function testAFullQueueDropsTheOldestPreparedEventFirst(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 10_000; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $queue->flush(1.0); // five in flight, 9 995 prepared

        for ($i = 0; $i < 6; ++$i) {
            $queue->add(new QueuedEvent($this->event('new-' . $i)));
        }

        $this->assertSame(['Event queue full, dropping oldest event'], $this->logger->messages());
        $this->assertSame(10_000, $queue->stats()['queueLength']);
        $transport->complete = true;
        $this->assertTrue($queue->flush());
        $names = $this->resourceNames($transport);
        $this->assertCount(10_005, $names);
        $this->assertSame(['event-4', 'event-6'], array_slice($names, 4, 2));
        $this->assertSame(['event-9999', 'new-0'], array_slice($names, 9_998, 2));
    }

    public function testAPreparedEventGoesToTheEndpointOfTheLastConfigure(): void
    {
        EventApiGuard::remove(); // the default URL: no variable at all; tearDown() puts the guard back
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 6; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $queue->flush(1.0);

        $queue->configure('https://later.example.test');
        $transport->complete = true;
        $queue->pump();
        $queue->pump();

        $this->assertCount(6, $transport->deliveries);
        $this->assertSame('https://api.agentcat.com/v1/public/events/', $transport->deliveries[4]->url);
        $this->assertSame('https://later.example.test/v1/public/events/', $transport->deliveries[5]->url);
    }

    public function testAnEventThatAHookDropsDuringAFlushLeavesTheQueue(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 5; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $queue->add(new QueuedEvent($this->event('dropped'), static fn(Event $event): ?Event => null));

        $queue->flush(1.0);

        $this->assertSame(['Event dropped by redactEvent hook'], $this->logger->messages());
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 5], $queue->stats());
    }

    /**
     * An event that goes to the exporters only, prepared by a flush, keeps its place: the exporters get it in its turn,
     * and an event that did not encode logs why after its export, as in a pass.
     */
    public function testAPreparedEventWithoutABodyIsExportedInItsTurn(): void
    {
        $transport = new RecordingTransport(complete: false);
        $exporter = new RecordingExporter(new \RuntimeException('boom'));
        $queue = new EventQueue(
            transport: $transport,
            exporters: $exporter,
            encode: static fn(Event $event): string => $event->resourceName === 'bad' ? throw new \RuntimeException('cannot encode') : '{}',
        );
        for ($i = 0; $i < 5; ++$i) {
            $queue->add(new QueuedEvent($this->event('event-' . $i)));
        }
        $telemetryOnly = $this->event('telemetry', null);
        $bad = $this->event('bad');
        $queue->add(new QueuedEvent($telemetryOnly));
        $queue->add(new QueuedEvent($bad));

        $queue->flush(1.0);

        $this->assertSame([], $this->logger->messages(), 'nothing exported yet: each export logs the exporter\'s error');
        $transport->complete = true;
        $transport->pump();
        $queue->pump();

        $this->assertCount(5, $transport->deliveries);
        $this->assertSame(['event-0', 'event-1', 'event-2', 'event-3', 'event-4', 'telemetry', 'bad'], array_map(static fn(Event $event): ?string => $event->resourceName, $exporter->events));
        $this->assertSame([$telemetryOnly, $bad], array_slice($exporter->events, 5));
        $this->assertSame(
            [...array_fill(0, 7, 'Telemetry export error: RuntimeException: boom'), 'Failed to encode event ' . $bad->id . ': RuntimeException: cannot encode'],
            $this->logger->messages(),
        );
    }

    #[Group('ac-03-9')]
    public function testShutdownCountsThePreparedEventsStillWaiting(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }
        $queue->flush(1.0);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();

        $this->assertSame(['Shutting down with 3 events still in queue'], $this->logger->messages());
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

    /** The shutdown function after a signal drain that used the whole budget: no hook runs, no transfer starts. */
    #[Group('ac-03-9')]
    public function testAShutdownAfterTheSharedDeadlineDispatchesNothing(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport);
        for ($i = 0; $i < 7; ++$i) {
            $queue->add(new QueuedEvent($this->event()));
        }
        $queue->shutdown(); // 5 in delivery until the deadline, 2 waiting
        $transport->complete = true;
        $transport->pump(); // the 5 slots are free again

        $queue->finalShutdown();
        $queue->shutdown();

        $this->assertCount(5, $transport->deliveries, 'nothing dispatched after the deadline');
        $this->assertSame(1, $transport->waits);
        $this->assertSame(array_fill(0, 3, 'Shutting down with 2 events still in queue'), $this->logger->messages());
    }

    /** A later shutdown before the deadline still delivers, until that deadline. */
    #[Group('ac-03-9')]
    public function testAShutdownBeforeTheSharedDeadlineStillDelivers(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $breakTransport = static function () use ($transport): void {
            $transport->failure = new \RuntimeException('down');
        };
        $queue->add(new QueuedEvent($this->event('first'), pending: [$breakTransport]));
        $queue->add(new QueuedEvent($this->event('second')));
        $queue->shutdown(); // fixes the deadline at 5 s; the first hook breaks the transport at 0 s
        $transport->failure = null;

        $queue->shutdown();

        $this->assertEqualsWithDelta(0.0, $transport->now(), PHP_FLOAT_EPSILON, 'still before the deadline');
        $this->assertSame(['second'], $this->resourceNames($transport));
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats());
    }

    public function testALaterShutdownWithAFailingClockNeverThrows(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $breakTransport = static function () use ($transport): void {
            $transport->failure = new \RuntimeException('down');
        };
        $queue->add(new QueuedEvent($this->event('first'), pending: [$breakTransport]));
        $queue->add(new QueuedEvent($this->event('second')));
        $queue->shutdown();
        $this->logger->records = [];

        $queue->shutdown(); // the deadline check cannot read the clock: the delivery loop logs the failure

        $this->assertSame([
            self::GAVE_UP . 'RuntimeException: down',
            self::GAVE_UP . 'RuntimeException: down',
            'Shutting down with 1 events still in queue',
        ], $this->logger->messages());
        $this->assertSame([], $transport->deliveries);
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

    #[Group('ac-06-63')]
    public function testFlushWaitsForTheSideChannelAndRunsItsCallbacks(): void
    {
        $channel = new RecordingHttpChannel();
        $answered = [];
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->posting($channel, static function (HttpResponse $r) use (&$answered): void {
            $answered[] = $r->status;
        }), channel: $channel);
        $queue->add(new QueuedEvent(new Event(eventType: 'mcp:tools/call', resourceName: 'a')));

        $this->assertTrue($queue->flush());

        $this->assertSame(['http://exporter.test/a'], $channel->urls());
        $this->assertSame([200], $answered);
    }

    #[Group('ac-06-63')]
    public function testFlushReportsFalseWhileASideRequestIsStillPending(): void
    {
        $channel = new RecordingHttpChannel(complete: false);
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->posting($channel), channel: $channel);
        $queue->add(new QueuedEvent(new Event(eventType: 'mcp:tools/call', resourceName: 'a')));

        $this->assertFalse($queue->flush(1.0));
        $this->assertSame(1, $channel->pending());
        $this->assertTrue($queue->hasWork());
    }

    /** AgentCat's own requests (diagnostics) never hold `flush()` up or make it false; `shutdown()` sends them. */
    #[Group('ac-06-63')]
    public function testFlushNeitherCountsNorWaitsForABackgroundRequest(): void
    {
        $channel = new RecordingHttpChannel(complete: false);
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}', background: true), static function (): void {});

        $this->assertTrue($queue->flush(1.0));

        $this->assertSame([], $channel->waits, 'flush() did not wait for it');
        $this->assertSame(1, $channel->pending());
        $this->assertTrue($queue->hasWork(), 'it is still there for shutdown()');
    }

    #[Group('ac-06-63')]
    public function testFlushStillWaitsForAnExporterRequestBesideABackgroundOne(): void
    {
        $channel = new RecordingHttpChannel(complete: false);
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}', background: true), static function (): void {});
        $channel->post(new HttpRequest('http://exporter.test/a', [], '{}'), static function (): void {});

        $this->assertFalse($queue->flush(1.0));
        $this->assertNotSame([], $channel->waits);
    }

    #[Group('ac-06-63')]
    public function testShutdownWaitsForABackgroundRequestInsideItsBudget(): void
    {
        $answered = [];
        $channel = new RecordingHttpChannel(complete: false, onWait: static function () use (&$channel): void {
            if ($channel instanceof RecordingHttpChannel) {
                $channel->complete = true; // the collector answers during the first wait
            }
        });
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}', background: true), static function () use (&$answered): void {
            $answered[] = 'diagnostics';
        });

        $queue->shutdown();

        $this->assertSame(['diagnostics'], $answered);
        $this->assertCount(1, $channel->waits);
    }

    /** The last pass after `finish()` waits for the background request that `finish()` posted. */
    #[Group('ac-06-63')]
    public function testShutdownWaitsForTheBackgroundRequestOfAFinishingCompanion(): void
    {
        $answered = [];
        $channel = new RecordingHttpChannel(complete: false, onWait: static function () use (&$channel): void {
            if ($channel instanceof RecordingHttpChannel) {
                $channel->complete = true;
            }
        });
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $queue->attach(new RecordingCompanion(static function () use ($channel, &$answered): void {
            $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}', background: true), static function () use (&$answered): void {
                $answered[] = 'diagnostics';
            });
        }));

        $queue->shutdown();

        $this->assertSame(['diagnostics'], $answered);
    }

    /**
     * @return iterable<string, array{float, list<float>, list<float>}> the timeout, then where each transport slice ends
     *                                                               and how long each channel slice is
     */
    public static function sliceDeadlines(): iterable
    {
        yield 'the channel slice is cut to the time left' => [0.035, [0.01, 0.03], [0.01, 0.005]];
        yield 'the transport slice ends at the deadline, the channel gets none' => [0.045, [0.01, 0.03, 0.045], [0.01, 0.01, 0.0]];
    }

    /**
     * Neither side waits on the other's sockets: up to 10 ms on the transport, then up to 10 ms on the channel, and
     * neither slice ends past the deadline. The channel double advances the recording clock by its wait.
     *
     * @param list<float> $transportEnds
     * @param list<float> $channelSlices
     */
    #[Group('ac-06-63')]
    #[DataProvider('sliceDeadlines')]
    public function testFlushWaitsInShortSlicesThatNeverEndPastTheDeadline(float $timeout, array $transportEnds, array $channelSlices): void
    {
        $transport = new RecordingTransport();
        $ends = ['transport' => [], 'channel' => []];
        $channel = new RecordingHttpChannel(complete: false, onWait: static function (float $seconds) use ($transport, &$ends): void {
            $ends['transport'][] = $transport->now();
            $transport->waitForActivity($transport->now() + $seconds);
            $ends['channel'][] = $transport->now();
        });
        $queue = new EventQueue(transport: $transport, channel: $channel);
        $channel->post(new HttpRequest('http://exporter.test/a', [], '{}'), static function (): void {});

        $this->assertFalse($queue->flush($timeout));

        $this->assertEqualsWithDelta($transportEnds, $ends['transport'], 1e-12);
        $this->assertEqualsWithDelta($channelSlices, $channel->waits, 1e-12);
        $this->assertEqualsWithDelta($timeout, $transport->now(), 1e-12, 'the last slice ends at the deadline');
        foreach ($ends['channel'] as $end) {
            $this->assertLessThanOrEqual($timeout + 1e-12, $end, 'no slice ends past the deadline');
        }
    }

    #[Group('ac-06-63')]
    public function testShutdownFinishesTheCompanionsAfterTheEventsAndDrivesTheirRequests(): void
    {
        $channel = new RecordingHttpChannel();
        $order = [];
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->posting($channel, static function () use (&$order): void {
            $order[] = 'export answered';
        }), channel: $channel);
        $queue->attach(new RecordingCompanion(static function () use ($channel, &$order): void {
            $order[] = 'finish';
            $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}'), static function () use (&$order): void {
                $order[] = 'diagnostics answered';
            });
        }));
        $queue->add(new QueuedEvent(new Event(projectId: 'proj_x', eventType: 'mcp:tools/call', resourceName: 'a')));

        $queue->shutdown();

        $this->assertSame(['export answered', 'finish', 'diagnostics answered'], $order);
    }

    public function testPumpTicksTheCompanionsAndPumpsTheSideChannel(): void
    {
        $channel = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $answered = 0;
        $channel->post(new HttpRequest('http://exporter.test/a', [], '{}'), static function () use (&$answered): void {
            ++$answered;
        });

        $queue->pump();

        $this->assertSame(['tick'], $companion->calls);
        $this->assertSame(1, $answered);
        $this->assertSame(0, $channel->pending());
    }

    public function testAThrowingCompanionNeverStopsThePass(): void
    {
        $channel = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $failing = new RecordingCompanion(tickFailure: new \RuntimeException('x'));
        $plain = new RecordingCompanion();
        $queue->attach($failing);
        $queue->attach($plain);
        $answered = 0;
        $channel->post(new HttpRequest('http://exporter.test/a', [], '{}'), static function () use (&$answered): void {
            ++$answered;
        });

        $queue->pump();

        $this->assertSame(['tick'], $failing->calls);
        $this->assertSame(['tick'], $plain->calls);
        $this->assertSame(1, $answered, 'the side channel still runs');
        $this->assertSame([], $this->logger->messages());
    }

    public function testAThrowingSideChannelNeverStopsTheQueue(): void
    {
        $transport = new RecordingTransport();
        $channel = new RecordingHttpChannel();
        $queue = new EventQueue(transport: $transport, channel: $channel);
        $channel->post(new HttpRequest('http://exporter.test/a', [], '{}'), static fn(): never => throw new \RuntimeException('callback'));
        $queue->add(new QueuedEvent($this->event()));

        $queue->pump();

        $this->assertCount(1, $transport->deliveries);
        $this->assertSame([], $this->logger->messages());
        $this->assertTrue($queue->flush());
    }

    /** A second shutdown after the shared deadline: the companions finished in the first one's reserve. */
    #[Group('ac-03-9')]
    public function testALaterShutdownAfterTheSharedDeadlineFinishesNothing(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport, shutdownTimeoutSeconds: 1.0);
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $queue->add(new QueuedEvent($this->event()));
        $queue->shutdown(); // the held event uses the budget up to the reserve, then the companion finishes

        $queue->shutdown();

        $this->assertSame(['tick', 'tick', 'finish'], $companion->calls, 'nothing after the deadline');
        $this->assertEqualsWithDelta(1.0, $transport->now(), PHP_FLOAT_EPSILON);
        $this->assertSame(2, $transport->waits);
    }

    /** @return iterable<string, array{float, float}> the shutdown timeout and when `finish()` runs */
    public static function finishReserves(): iterable
    {
        yield '10 % of 1 s' => [1.0, 0.9];
        yield '10 % of 2 s' => [2.0, 1.8];
        yield 'at most 0.5 s of the default 5 s' => [EventQueue::SHUTDOWN_TIMEOUT_SECONDS, 4.5];
        yield 'at most 0.5 s of 10 s' => [10.0, 9.5];
    }

    /**
     * A slow AgentCat API (or a hung exporter) never keeps the companions from finishing: their requests get the end of
     * the budget, and the shutdown still ends at its deadline. The channel double advances the recording clock.
     */
    #[Group('ac-03-9')]
    #[Group('ac-06-63')]
    #[DataProvider('finishReserves')]
    public function testWithCompanionsTheEndOfTheBudgetIsKeptForFinish(float $timeout, float $finishAt): void
    {
        $transport = new RecordingTransport(complete: false);
        $channel = new RecordingHttpChannel(complete: false, onWait: static function (float $seconds) use ($transport): void {
            $transport->waitForActivity($transport->now() + $seconds);
        });
        $queue = new EventQueue(transport: $transport, shutdownTimeoutSeconds: $timeout, channel: $channel);
        $finishedAt = [];
        $queue->attach(new RecordingCompanion(static function () use ($transport, $channel, &$finishedAt): void {
            $finishedAt[] = $transport->now();
            $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}'), static function (): void {});
        }));
        $queue->add(new QueuedEvent($this->event())); // never completes

        $queue->shutdown();

        $this->assertCount(1, $finishedAt);
        $this->assertEqualsWithDelta($finishAt, $finishedAt[0], 1e-9, 'finish() runs when only the reserve is left');
        $this->assertNotSame([], $channel->waits, 'the last pass waits for the request');
        $this->assertEqualsWithDelta($timeout, $transport->now(), 1e-9, 'the last pass ends at the deadline');
        $this->assertSame(1, $channel->pending());
        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{float}> */
    public static function noBudget(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'NaN' => [NAN];
    }

    /** No time left, no reserve: nothing finishes, nothing waits, and no NaN reaches a deadline. */
    #[DataProvider('noBudget')]
    public function testWithoutABudgetNoCompanionFinishes(float $timeout): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport, shutdownTimeoutSeconds: $timeout);
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();

        $this->assertSame(['tick'], $companion->calls);
        $this->assertSame(0, $transport->waits);
        $this->assertSame([], $this->logger->messages());
    }

    /** A hook or an exporter that calls `shutdown()` inside `pump()`: the companions finish only after the delivery. */
    #[Group('ac-03-9')]
    public function testAReentrantShutdownFinishesNoCompanion(): void
    {
        $transport = new RecordingTransport();
        $exporter = new class implements ExporterSink {
            public ?EventQueue $queue = null;

            #[\Override]
            public function export(Event $event): void
            {
                $this->queue?->shutdown();
            }
        };
        $queue = new EventQueue(transport: $transport, exporters: $exporter);
        $exporter->queue = $queue;
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $queue->add(new QueuedEvent($this->event()));

        $queue->pump();

        $this->assertSame(['tick'], $companion->calls, 'no finish before the event is delivered');
        $this->assertCount(1, $transport->deliveries);

        $queue->shutdown();

        $this->assertSame(['tick', 'tick', 'finish'], $companion->calls);
    }

    /** The same call inside a shutdown's first pass, after that shutdown fixed the deadline: still no early finish. */
    #[Group('ac-03-9')]
    public function testAReentrantShutdownDuringAShutdownFinishesNoCompanion(): void
    {
        $transport = new RecordingTransport();
        $exporter = new class implements ExporterSink {
            public ?EventQueue $queue = null;

            #[\Override]
            public function export(Event $event): void
            {
                $this->queue?->shutdown();
            }
        };
        $queue = new EventQueue(transport: $transport, exporters: $exporter);
        $exporter->queue = $queue;
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();

        $this->assertSame(['tick', 'finish'], $companion->calls);
        $this->assertCount(1, $transport->deliveries);
    }

    /** A clock that cannot be read never fixes the deadline: one failure line per shutdown, no companion finishes. */
    public function testAFailingClockFinishesNoCompanion(): void
    {
        $transport = new RecordingTransport(failure: new \RuntimeException('down'));
        $queue = new EventQueue(transport: $transport);
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();
        $queue->shutdown();

        $this->assertSame([], $companion->calls);
        $this->assertSame(array_merge(...array_fill(0, 2, [self::GAVE_UP . 'RuntimeException: down', 'Shutting down with 1 events still in queue'])), $this->logger->messages());
    }

    /** `finish()` is the last call a companion gets in a shutdown: the pass after it drives the channel only. */
    #[Group('ac-03-9')]
    public function testShutdownFinishesEachCompanionOnceAfterDeliveringEverything(): void
    {
        $transport = new RecordingTransport();
        $queue = new EventQueue(transport: $transport);
        $first = new RecordingCompanion();
        $second = new RecordingCompanion();
        $queue->attach($first);
        $queue->attach($second);
        $queue->add(new QueuedEvent($this->event()));

        $queue->shutdown();

        $this->assertSame(['tick', 'finish'], $first->calls);
        $this->assertSame(['tick', 'finish'], $second->calls);
        $this->assertCount(1, $transport->deliveries);
        $this->assertSame([], $this->logger->messages());
    }

    public function testAThrowingFinishNeverStopsTheShutdown(): void
    {
        $channel = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $failing = new RecordingCompanion(static fn(): never => throw new \RuntimeException('finish'));
        $posting = new RecordingCompanion(static function () use ($channel): void {
            $channel->post(new HttpRequest('http://collector.test/v1/logs', [], '{}'), static function (): void {});
        });
        $queue->attach($failing);
        $queue->attach($posting);

        $queue->shutdown();

        $this->assertSame(['tick', 'finish'], $failing->calls);
        $this->assertSame(['tick', 'finish'], $posting->calls);
        $this->assertSame(['http://collector.test/v1/logs'], $channel->urls());
        $this->assertSame(0, $channel->pending(), 'the last request was driven');
        $this->assertSame([], $this->logger->messages());
    }

    public function testHasWorkIsFalseWhenNothingIsLeft(): void
    {
        $queue = new EventQueue(transport: new RecordingTransport(), channel: new RecordingHttpChannel());
        $queue->attach(new RecordingCompanion());

        $this->assertFalse($queue->hasWork());
    }

    public function testHasWorkCountsEventsSideRequestsAndCompanions(): void
    {
        $waiting = new EventQueue(transport: new RecordingTransport());
        $waiting->add(new QueuedEvent($this->event()));
        $this->assertTrue($waiting->hasWork(), 'an event waits');

        $inDelivery = new EventQueue(transport: new RecordingTransport(complete: false));
        $inDelivery->add(new QueuedEvent($this->event()));
        $inDelivery->pump();
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 1], $inDelivery->stats());
        $this->assertTrue($inDelivery->hasWork(), 'an event in delivery');

        $channel = new RecordingHttpChannel(complete: false);
        $side = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $channel->post(new HttpRequest('http://exporter.test/a', [], '{}'), static function (): void {});
        $this->assertTrue($side->hasWork(), 'a side request is pending');

        $buffering = new RecordingCompanion();
        $companions = new EventQueue(transport: new RecordingTransport());
        $companions->attach(new RecordingCompanion());
        $companions->attach($buffering);
        $this->assertFalse($companions->hasWork());
        $buffering->work = true;
        $this->assertTrue($companions->hasWork(), 'a companion has work');
    }

    public function testHasWorkIsTrueWhenACheckThrows(): void
    {
        $queue = new EventQueue(transport: new RecordingTransport(failure: new \RuntimeException('down')));

        $this->assertTrue($queue->hasWork());
        $this->assertSame([], $this->logger->messages());
    }

    public function testArmRunsTheDeliveryTriggersWithoutAnEvent(): void
    {
        $count = 0;
        $queue = new EventQueue(transport: new RecordingTransport(), onEnqueue: static function () use (&$count): void {
            ++$count;
        });

        $queue->arm();
        $queue->arm();

        $this->assertSame(2, $count);
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $queue->stats());
        $this->assertFalse($queue->hasWork());
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-03-13')]
    public function testArmLogsAFailingRegistrar(): void
    {
        $queue = new EventQueue(transport: new RecordingTransport(), onEnqueue: static fn(): never => throw new \RuntimeException('no'));

        $queue->arm();

        $this->assertSame(['Failed to register AgentCat event delivery as a background task: RuntimeException: no'], $this->logger->messages());
    }

    public function testArmWithoutARegistrarDoesNothing(): void
    {
        $queue = new EventQueue(transport: new RecordingTransport());

        $queue->arm();

        $this->assertFalse($queue->hasWork());
        $this->assertSame([], $this->logger->messages());
    }

    public function testExporterSinkIsTheOneGivenThenTheOneSet(): void
    {
        $first = new RecordingExporter();
        $second = new RecordingExporter();
        $queue = new EventQueue(transport: new RecordingTransport(), exporters: $first);

        $this->assertSame($first, $queue->exporterSink());

        $queue->setExporterSink($second);

        $this->assertSame($second, $queue->exporterSink());
    }

    public function testChannelIsTheOneGivenElseACurlChannel(): void
    {
        $channel = new RecordingHttpChannel();

        $this->assertSame($channel, (new EventQueue(transport: new RecordingTransport(), channel: $channel))->channel());
        $this->assertInstanceOf(CurlHttpChannel::class, (new EventQueue(transport: new RecordingTransport()))->channel());
    }

    private function event(string $resourceName = 'tool', ?string $projectId = 'proj_test'): Event
    {
        return new Event(projectId: $projectId, eventType: 'mcp:tools/call', resourceName: $resourceName);
    }

    /**
     * An event whose pending hook takes `HOOK_SECONDS` on the transport's simulated clock and appends its resource name
     * to `$ran`.
     *
     * @param \ArrayObject<int, string> $ran
     */
    private function slow(string $resourceName, \ArrayObject $ran, RecordingTransport $transport): QueuedEvent
    {
        return new QueuedEvent($this->event($resourceName), pending: [
            static function () use ($resourceName, $ran, $transport): void {
                $transport->waitForActivity($transport->now() + self::HOOK_SECONDS);
                $ran->append($resourceName);
            },
        ]);
    }

    /**
     * A `redactEvent` hook that queues a telemetry-only `child` event with the same hook on every run, up to
     * `SELF_FEEDING_BOUND`, and appends the child to `$children`. With `$transport`, each run takes `HOOK_SECONDS` on its
     * simulated clock.
     *
     * @param \ArrayObject<int, string> $children
     *
     * @return \Closure(Event): Event
     */
    private function selfFeeding(EventQueue $queue, \ArrayObject $children, ?RecordingTransport $transport = null): \Closure
    {
        $redact = null;
        $redact = function (Event $event) use (&$redact, $queue, $children, $transport): Event {
            $transport?->waitForActivity($transport->now() + self::HOOK_SECONDS);
            if (count($children) < self::SELF_FEEDING_BOUND) {
                $children->append('child');
                $queue->add(new QueuedEvent($this->event('child', null), $redact));
            }

            return $event;
        };

        return $redact;
    }

    /**
     * An event whose pending hook appends its resource name to `$ran` when it runs.
     *
     * @param \ArrayObject<int, string> $ran
     */
    private function recorded(string $resourceName, \ArrayObject $ran): QueuedEvent
    {
        return new QueuedEvent($this->event($resourceName), pending: [
            static function () use ($resourceName, $ran): void {
                $ran->append($resourceName);
            },
        ]);
    }

    /** @return list<mixed> the `resource_name` of every delivery body */
    private function resourceNames(RecordingTransport $transport): array
    {
        return array_map(
            static fn(Delivery $delivery): mixed => JsonAssert::at(Json::decode($delivery->body), 'resource_name'),
            $transport->deliveries,
        );
    }

    /**
     * An exporter sink that posts one side request per event, like an exporter does.
     *
     * @param (\Closure(HttpResponse): void)|null $onDone
     */
    private function posting(HttpChannel $channel, ?\Closure $onDone = null): ExporterSink
    {
        return new readonly class ($channel, $onDone) implements ExporterSink {
            /** @param (\Closure(HttpResponse): void)|null $onDone */
            public function __construct(private HttpChannel $channel, private ?\Closure $onDone) {}

            #[\Override]
            public function export(Event $event): void
            {
                $this->channel->post(new HttpRequest('http://exporter.test/' . $event->resourceName, [], '{}'), $this->onDone ?? static function (): void {});
            }
        };
    }

    public function testAQueueWithoutADrainHasNoSignalHandlersToDisable(): void
    {
        $this->assertTrue((new EventQueue(transport: new RecordingTransport()))->disableSignalHandlers());
    }

    #[RequiresPhpExtension('pcntl')]
    public function testDisableSignalHandlersAsksTheDrain(): void
    {
        $term = pcntl_signal_get_handler(\SIGTERM);
        $int = pcntl_signal_get_handler(\SIGINT);
        $async = pcntl_async_signals();

        try {
            $fresh = new EventQueue(transport: new RecordingTransport(), signalDrain: new SignalDrain(static fn(): EventQueue => throw new \LogicException('unused'), null, static fn(string $f): bool => true, 'cli'));
            $this->assertTrue($fresh->disableSignalHandlers());

            $drain = new SignalDrain(static fn(): EventQueue => throw new \LogicException('unused'), null, static fn(string $f): bool => true, 'cli');
            $drain->install();
            $installed = new EventQueue(transport: new RecordingTransport(), signalDrain: $drain);
            $this->assertFalse($installed->disableSignalHandlers());
        } finally {
            pcntl_signal(\SIGTERM, $term);
            pcntl_signal(\SIGINT, $int);
            pcntl_async_signals($async);
        }
    }
}
