<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\Transport;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingCompanion;
use AgentCat\Tests\Support\RecordingDeferredWork;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/** `EventQueue::defer()`: when the queue runs deferred work, when it drops it, and that the work adds no wait. */
final class EventQueueDeferredWorkTest extends TestCase
{
    private RecordingTransport $transport;

    private RecordingHttpChannel $channel;

    #[\Override]
    protected function setUp(): void
    {
        Log::setLogger(new MemoryLogger());
        $this->transport = new RecordingTransport();
        $this->channel = new RecordingHttpChannel();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testDeferredWorkCountsAsWorkAndArmsTheDeliveryTriggers(): void
    {
        $armed = 0;
        $queue = new EventQueue(transport: $this->transport, onEnqueue: static function () use (&$armed): void {
            ++$armed;
        }, channel: $this->channel, outlivesRequest: false);
        $this->assertFalse($queue->hasWork());

        $queue->defer('a', new RecordingDeferredWork());

        $this->assertTrue($queue->hasWork());
        $this->assertSame(1, $armed);
    }

    public function testAProcessThatOutlivesItsRequestsRunsTheWorkOnceInTheNextPump(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        $this->assertSame([], $work->runs, 'defer() runs nothing');

        $queue->pump();
        $queue->pump();

        $this->assertSame([INF], $work->runs);
        $this->assertFalse($queue->hasWork());
    }

    public function testWorkWithTheKeyOfWaitingWorkReplacesIt(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $first = new RecordingDeferredWork();
        $second = new RecordingDeferredWork();
        $other = new RecordingDeferredWork();
        $queue->defer('a', $first);
        $queue->defer('b', $other);
        $queue->defer('a', $second);

        $queue->pump();

        $this->assertSame([[], [INF], [INF]], [$first->runs, $second->runs, $other->runs]);
    }

    public function testAPumpStartsTheEventsFirstAndRunsTheWorkWhileTheyAreInFlight(): void
    {
        $this->transport->complete = false;
        $queue = $this->queue(outlivesRequest: true);
        $started = null;
        $work = new RecordingDeferredWork(function () use (&$started): void {
            $started = count($this->transport->deliveries);
        });
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));

        $queue->pump();

        $this->assertSame(1, $started, 'the event was started before the work ran');
        $this->assertSame(1, $this->transport->inFlight());
    }

    public function testAPumpKeepsTheWorkWhileAnEventStillWaitsForAFreeSlot(): void
    {
        $this->transport->complete = false;
        $queue = $this->queue(outlivesRequest: true);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        for ($i = 0; $i <= EventQueue::CONCURRENCY; ++$i) {
            $queue->add($this->event('evt_' . $i));
        }

        $queue->pump();
        $this->assertSame([], $work->runs);

        $this->transport->complete = true;
        $queue->pump(); // frees the slots
        $queue->pump(); // starts the last event

        $this->assertSame([INF], $work->runs);
    }

    public function testADeliveryThatWaitsRunsTheWorkOnlyOnceTheEventsAreDeliveredAndGivesItTheTimeLeft(): void
    {
        $this->transport->complete = false;
        $queue = $this->queue(outlivesRequest: true);
        $inFlight = null;
        $work = new RecordingDeferredWork(function () use (&$inFlight): void {
            $inFlight = $this->transport->inFlight();
        });
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));

        $this->assertFalse($queue->flush(1.0), 'the event is still in flight when the window ends');
        $this->assertSame([], $work->runs, 'the work waits for the event');
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON);

        $this->transport->complete = true;
        $this->assertTrue($queue->flush(1.0));

        $this->assertSame([1.0], $work->runs);
        $this->assertSame(0, $inFlight);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the second flush did not wait');
    }

    public function testAFlushWithoutAWindowRunsNoWork(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->flush(0.0);
        $queue->flush(-1.0);
        $queue->flush(NAN);
        $this->assertSame([], $work->runs);

        $queue->flush();
        $this->assertSame([INF], $work->runs);
    }

    public function testAFlushWaitsForWhatTheWorkPostsOnlyInsideItsWindow(): void
    {
        $this->channel->complete = false; // the API never answers
        $queue = $this->queue(outlivesRequest: true);
        $queue->defer('a', new RecordingDeferredWork(function (): void {
            $this->channel->post(new HttpRequest('https://example.test/', [], '{}'), static function (): void {});
        }));

        $this->assertFalse($queue->flush(1.0));

        $this->assertCount(1, $this->channel->requests);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the wait ended at the 1 s of the window, as for an event');
    }

    public function testTheShutdownOfAProcessThatOutlivesItsRequestsDropsTheWork(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->shutdown();

        $this->assertSame([], $work->runs);
        $this->assertSame(['the process is ending'], $work->drops);
        $this->assertEqualsWithDelta(0.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the shutdown did not wait');
        $this->assertFalse($queue->hasWork());
    }

    public function testAPerRequestSapiRunsNoWorkInAPumpOrAFlush(): void
    {
        $queue = $this->queue(outlivesRequest: false);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->pump();
        $queue->flush();
        $queue->flush(1.0);

        $this->assertSame([[], []], [$work->runs, $work->drops]);
        $this->assertTrue($queue->hasWork());
    }

    public function testAPerRequestSapiDropsTheWorkAtShutdownWhenTheClientDoesNotHaveItsResponse(): void
    {
        $queue = $this->queue(outlivesRequest: false);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->shutdown();

        $this->assertSame([], $work->runs);
        $this->assertSame(['the client does not have its response yet'], $work->drops);
    }

    public function testAPerRequestSapiRunsTheWorkInTheShutdownAfterTheResponseAndAfterTheEvents(): void
    {
        $queue = $this->queue(outlivesRequest: false);
        $delivered = null;
        $work = new RecordingDeferredWork(function () use (&$delivered): void {
            $delivered = [count($this->transport->deliveries), $this->transport->inFlight()];
        });
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));
        $queue->pump(); // a pump before the response runs no work
        $this->assertSame([], $work->runs);

        $queue->responseSent();
        $queue->finalShutdown();

        $this->assertSame([EventQueue::SHUTDOWN_TIMEOUT_SECONDS], $work->runs);
        $this->assertSame([1, 0], $delivered);
        $this->assertSame([], $work->drops);
    }

    public function testAPendingRequestOfTheWorkDoesNotMoveTheShutdownDeadline(): void
    {
        $this->channel->complete = false; // the API never answers
        $queue = $this->queue(outlivesRequest: false);
        $queue->defer('a', new RecordingDeferredWork(function (): void {
            $this->channel->post(new HttpRequest('https://example.test/', [], '{}'), static function (): void {});
        }));
        $queue->responseSent();

        $queue->finalShutdown();

        $this->assertCount(1, $this->channel->requests);
        $this->assertSame(EventQueue::SHUTDOWN_TIMEOUT_SECONDS, $this->transport->now(), 'the one 5 s deadline of the events');
    }

    public function testEventsThatUseTheWholeShutdownTimeLeaveNoneForTheWork(): void
    {
        $this->transport->complete = false; // the events are never delivered
        $queue = $this->queue(outlivesRequest: false);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));
        $queue->responseSent();

        $queue->finalShutdown();

        $this->assertSame([], $work->runs);
        $this->assertSame(['the delivery time ended before the events were delivered'], $work->drops);
        $this->assertSame(EventQueue::SHUTDOWN_TIMEOUT_SECONDS, $this->transport->now());
    }

    public function testWithACompanionTheWorkGetsTheTimeBeforeTheFinishReserve(): void
    {
        $queue = $this->queue(outlivesRequest: false);
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        $queue->responseSent();

        $queue->finalShutdown();

        $this->assertSame([4.5], $work->runs);
        $this->assertContains('finish', $companion->calls);
    }

    public function testAQueueThatIsShuttingDownDropsNewWorkAtOnce(): void
    {
        $queue = $this->queue(outlivesRequest: false);
        $queue->shutdown();
        $work = new RecordingDeferredWork();

        $queue->defer('a', $work);

        $this->assertSame(['the queue is shutting down'], $work->drops);
        $this->assertFalse($queue->hasWork());
    }

    public function testWorkThatThrowsStopsNeitherTheOtherWorkNorTheQueue(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $failing = new RecordingDeferredWork(failure: new \RuntimeException('boom'));
        $next = new RecordingDeferredWork();
        $queue->defer('a', $failing);
        $queue->defer('b', $next);
        $queue->add($this->event('evt_1'));

        $queue->pump();
        $this->assertSame([[INF], [INF]], [$failing->runs, $next->runs]);
        $this->assertCount(1, $this->transport->deliveries);

        $queue->defer('c', $failing);
        $queue->defer('d', $next);
        $queue->shutdown();
        $this->assertSame([['the process is ending'], ['the process is ending']], [$failing->drops, $next->drops]);

        $queue->defer('e', $failing); // dropped at once, and its error stays inside
        $this->assertCount(2, $failing->drops);
    }

    public function testADeliveryWhoseClockFailsKeepsTheWork(): void
    {
        $transport = new class implements Transport {
            public int $reads = 0;

            #[\Override]
            public function start(Delivery $delivery): void {}

            #[\Override]
            public function pump(): void {}

            #[\Override]
            public function waitForActivity(float $deadline): bool
            {
                return true;
            }

            #[\Override]
            public function drain(float $deadline): bool
            {
                return true;
            }

            #[\Override]
            public function send(Delivery $delivery, float $deadline = INF): bool
            {
                return true;
            }

            #[\Override]
            public function inFlight(): int
            {
                return 0;
            }

            #[\Override]
            public function now(): float
            {
                return ++$this->reads === 1 ? 0.0 : throw new \RuntimeException('no clock');
            }
        };
        $queue = new EventQueue(transport: $transport, channel: $this->channel, outlivesRequest: true);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $this->assertTrue($queue->flush(1.0), 'no event is left: the flush does not count the work');

        $this->assertSame([[], []], [$work->runs, $work->drops]);
        $this->assertTrue($queue->hasWork(), 'the work waits for a pass that can read the time');
    }

    public function testWorkThatDefersMoreWorkLeavesItForTheNextPass(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $later = new RecordingDeferredWork();
        $queue->defer('a', new RecordingDeferredWork(static function () use ($queue, $later): void {
            $queue->defer('a', $later);
        }));

        $queue->pump();
        $this->assertSame([], $later->runs);

        $queue->pump();
        $this->assertSame([INF], $later->runs);
    }

    public function testTheBaseUrlFollowsTheLastConfigure(): void
    {
        $queue = $this->queue(outlivesRequest: true);
        $this->assertStringStartsWith('http://127.0.0.1:', $queue->baseUrl(), 'the guard of the test process');

        $queue->configure('https://custom.example.test/base/');

        $this->assertSame('https://custom.example.test/base', $queue->baseUrl());
    }

    private function queue(bool $outlivesRequest): EventQueue
    {
        return new EventQueue(transport: $this->transport, channel: $this->channel, outlivesRequest: $outlivesRequest);
    }

    private function event(string $id): QueuedEvent
    {
        return new QueuedEvent(new Event(id: $id, projectId: 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa', eventType: 'mcp:tools/call'));
    }
}
