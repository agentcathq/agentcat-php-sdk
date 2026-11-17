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
use AgentCat\Tests\Support\RecordingOutstanding;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * `EventQueue::defer()`: the queue runs deferred work only in its own windows after the response, drops what a window
 * cannot run, and the work adds no wait.
 */
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

    public function testAProcessWithoutAWindowAfterTheResponseTakesNoWork(): void
    {
        $armed = 0;
        $queue = new EventQueue(transport: $this->transport, onEnqueue: static function () use (&$armed): void {
            ++$armed;
        }, channel: $this->channel, finishesRequests: false);
        $work = new RecordingDeferredWork();
        $this->assertFalse($queue->canDefer(), 'a stdio server or a plain worker loop');

        $queue->defer('a', $work);

        $this->assertSame(['this process has no delivery after the response'], $work->drops);
        $this->assertFalse($queue->hasWork());
        $this->assertSame(0, $armed);
        $queue->pump();
        $queue->flush();
        $queue->shutdown();
        $this->assertSame([], $work->runs);
    }

    public function testTheSapiDecidesWhenNothingIsGiven(): void
    {
        $this->assertFalse((new EventQueue(transport: $this->transport, channel: $this->channel))->canDefer(), 'the command line has no finish call');
    }

    public function testDeferredWorkCountsAsWorkAndArmsTheDeliveryTriggers(): void
    {
        $armed = 0;
        $queue = new EventQueue(transport: $this->transport, onEnqueue: static function () use (&$armed): void {
            ++$armed;
        }, channel: $this->channel, finishesRequests: true);
        $this->assertTrue($queue->canDefer());
        $this->assertFalse($queue->hasWork());

        $queue->defer('a', new RecordingDeferredWork());

        $this->assertTrue($queue->hasWork());
        $this->assertSame(1, $armed);
    }

    public function testAWorkerCanDeferOnceABridgeEndedARequestInAWindow(): void
    {
        $queue = $this->queue(finishes: false);
        $queue->endRequest(0.0); // a console command or a per-request SAPI: no window
        $queue->endRequest(-1.0);
        $queue->endRequest(NAN);
        $this->assertFalse($queue->canDefer());

        $this->assertTrue($queue->endRequest(1.0));

        $this->assertTrue($queue->canDefer());
        $this->assertEqualsWithDelta(0.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'an empty window does not wait');
    }

    public function testNoPumpAndNoFlushEverRunsTheWork(): void
    {
        foreach ([$this->worker(), $this->queue(finishes: true)] as $queue) {
            $work = new RecordingDeferredWork();
            $queue->defer('a', $work);

            $queue->pump();
            $queue->pump();
            $queue->flush(0.0);
            $queue->flush(1.0);
            $this->assertTrue($queue->flush(), 'a flush without a limit does not count the work');

            $this->assertSame([[], []], [$work->runs, $work->drops]);
            $this->assertTrue($queue->hasWork());
        }
        $this->assertEqualsWithDelta(0.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'no flush waited for the work');
    }

    public function testAFlushNeverWaitsForWhatTheWorkPosted(): void
    {
        $this->channel->complete = false; // the API never answers
        $queue = $this->worker();
        $queue->defer('a', new RecordingDeferredWork(function (): void {
            $this->channel->post(new HttpRequest('https://example.test/', [], '{}', true), static function (): void {});
        }));
        $queue->endRequest(1.0);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON);

        $this->assertTrue($queue->flush());
        $this->assertTrue($queue->flush(5.0));

        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the flushes returned at once');
        $this->assertSame(1, $this->channel->pending());
    }

    public function testTheRequestEndWindowRunsTheWorkOnceWhenTheEventsAreDeliveredAndGivesItTheTimeLeft(): void
    {
        $queue = $this->worker();
        $state = null;
        $work = new RecordingDeferredWork(function () use (&$state): void {
            $state = [count($this->transport->deliveries), $this->transport->inFlight()];
        });
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));

        $this->assertTrue($queue->endRequest(0.5));
        $queue->endRequest(0.5);

        $this->assertSame([0.5], $work->runs, 'the work gets exactly what is left of the window');
        $this->assertSame([1, 0], $state, 'the event was delivered before the work ran');
        $this->assertSame([], $work->drops);
        $this->assertFalse($queue->hasWork());
    }

    public function testEventsThatAreDeliveredOnlyAtTheEndOfTheWindowLeaveNoTimeForTheWork(): void
    {
        $this->transport->complete = false;
        $channel = new RecordingHttpChannel(complete: false, onWait: function (): void {
            $this->transport->complete = $this->transport->now() >= 1.0; // the events are delivered when the window is over
        });
        $queue = new EventQueue(transport: $this->transport, channel: $channel, finishesRequests: false);
        $queue->endRequest(1.0);
        $channel->post(new HttpRequest('https://exporter.test/', [], '{}'), static function (): void {}); // makes the queue wait in slices
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));

        $this->assertFalse($queue->endRequest(1.0));

        $this->assertSame(0, $this->transport->inFlight(), 'the event was delivered in the last pass');
        $this->assertSame([], $work->runs, 'with 0 s left, the work does not start');
        $this->assertSame(['the request-end window ended before the events were delivered'], $work->drops);
    }

    public function testTheTimeLeftIsCountedFromTheClockOfTheWindow(): void
    {
        $this->transport->complete = false;
        $queue = $this->worker();
        $queue->add($this->event('evt_1'));
        $queue->endRequest(2.0); // the clock of the transport is at 2 s now
        $this->transport->complete = true;
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->endRequest(0.5);

        $this->assertSame([0.5], $work->runs);
    }

    public function testWorkWithTheKeyOfWaitingWorkReplacesIt(): void
    {
        $queue = $this->worker();
        $first = new RecordingDeferredWork();
        $second = new RecordingDeferredWork();
        $other = new RecordingDeferredWork();
        $queue->defer('a', $first);
        $queue->defer('b', $other);
        $queue->defer('a', $second);

        $queue->endRequest(1.0);

        $this->assertSame([[], [1.0], [1.0]], [$first->runs, $second->runs, $other->runs]);
    }

    public function testAWindowThatEndsWithAnEventInFlightDropsTheWorkAndNoLaterPassRunsIt(): void
    {
        $this->transport->complete = false; // the event is never delivered in the window
        $queue = $this->worker();
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));

        $this->assertFalse($queue->endRequest(1.0));

        $this->assertSame([], $work->runs);
        $this->assertSame(['the request-end window ended before the events were delivered'], $work->drops);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the window is the 1 s of the event send');
        $this->assertSame(1, $this->transport->inFlight());

        $this->transport->complete = true; // the next request
        $queue->pump();
        $queue->endRequest(1.0);
        $this->assertSame([], $work->runs, 'the work of the earlier request is gone');
        $this->assertFalse($queue->hasWork());
    }

    public function testTheWindowWaitsForWhatTheWorkPostedOnlyUntilItsOwnDeadline(): void
    {
        $this->channel->complete = false; // the API never answers
        $queue = $this->worker();
        $queue->defer('a', new RecordingDeferredWork(function (): void {
            $this->channel->post(new HttpRequest('https://example.test/', [], '{}', true), static function (): void {});
        }));

        $this->assertFalse($queue->endRequest(1.0));

        $this->assertCount(1, $this->channel->requests);
        $this->assertEqualsWithDelta(1.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the wait ended at the 1 s of the window, as for an event');
    }

    public function testAWindowWithoutWorkDoesNotWaitForBackgroundRequests(): void
    {
        $this->channel->complete = false;
        $queue = $this->worker();
        $this->channel->post(new HttpRequest('https://diagnostics.test/', [], '{}', true), static function (): void {});

        $this->assertTrue($queue->endRequest(1.0));

        $this->assertEqualsWithDelta(0.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'as a flush: the diagnostics go on in later passes');
    }

    public function testTheShutdownOfAWorkerRunsNoWorkAndSaysWhatItGaveUp(): void
    {
        $queue = $this->worker();
        $waiting = new RecordingDeferredWork();
        $posted = new RecordingDeferredWork();
        $posted->leaves = $onItsWay = new RecordingOutstanding();
        $answered = new RecordingDeferredWork();
        $answered->leaves = $settled = new RecordingOutstanding();
        $queue->defer('a', $posted);
        $queue->defer('b', $answered);
        $queue->endRequest(1.0);
        $settled->settled = true; // the API answered
        $queue->defer('c', $waiting);

        $queue->shutdown();

        $this->assertSame([[], ['the process is ending']], [$waiting->runs, $waiting->drops]);
        $this->assertSame(['the delivery time ended before the AgentCat API answered'], $onItsWay->abandons, 'what the work started was still on its way');
        $this->assertSame([[], [], []], [$settled->abandons, $posted->drops, $answered->drops]);
        $this->assertEqualsWithDelta(0.0, $this->transport->now(), PHP_FLOAT_EPSILON, 'the shutdown did not wait');
        $this->assertFalse($queue->hasWork());
        $queue->shutdown();
        $this->assertSame(['the delivery time ended before the AgentCat API answered'], $onItsWay->abandons, 'a second shutdown says nothing again');
    }

    public function testTheQueueLetsGoOfWorkWhenItsRunEndsAndOfWhatItLeftWhenThatIsAnswered(): void
    {
        $queue = $this->worker();
        $work = new RecordingDeferredWork();
        $work->leaves = $left = new RecordingOutstanding();
        $ranWork = \WeakReference::create($work);
        $onItsWay = \WeakReference::create($left);
        $queue->defer('a', $work);

        $queue->endRequest(1.0);
        unset($work);
        $this->assertNull($ranWork->get(), 'the queue holds no work after its run, also while what it left is on its way');
        $this->assertInstanceOf(RecordingOutstanding::class, $onItsWay->get());

        $left->settled = true; // the API answered
        unset($left);
        $queue->pump(); // the pass that reads the answer

        $this->assertNull($onItsWay->get(), 'forgotten in the pass that settled it, not at the next run');
    }

    public function testWorkThatIsDroppedIsNotKept(): void
    {
        $queue = $this->worker();
        $this->transport->complete = false;
        $work = new RecordingDeferredWork();
        $dropped = \WeakReference::create($work);
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));

        $queue->endRequest(1.0);
        unset($work);

        $this->assertNull($dropped->get());
    }

    public function testAPerRequestSapiDropsTheWorkAtShutdownWhenTheClientDoesNotHaveItsResponse(): void
    {
        $queue = $this->queue(finishes: true);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->endRequest(0.0); // the bridge's hook under a per-request SAPI: the shutdown function follows
        $queue->endRequest(1.0); // and a window here is no window after the response of this SAPI's request...
        $this->assertSame([1.0], $work->runs, '...but a bridge that asks for one gets it');

        $late = new RecordingDeferredWork();
        $queue->defer('b', $late);
        $queue->shutdown();

        $this->assertSame([], $late->runs);
        $this->assertSame(['the client does not have its response yet'], $late->drops);
    }

    public function testAPerRequestSapiRunsTheWorkInTheShutdownAfterTheResponseAndAfterTheEvents(): void
    {
        $queue = $this->queue(finishes: true);
        $delivered = null;
        $work = new RecordingDeferredWork(function () use (&$delivered): void {
            $delivered = [count($this->transport->deliveries), $this->transport->inFlight()];
        });
        $queue->defer('a', $work);
        $queue->add($this->event('evt_1'));
        $queue->pump();
        $queue->endRequest(0.0);
        $this->assertSame([], $work->runs, 'nothing runs before the response');

        $queue->responseSent();
        $queue->finalShutdown();

        $this->assertSame([EventQueue::SHUTDOWN_TIMEOUT_SECONDS], $work->runs);
        $this->assertSame([1, 0], $delivered);
        $this->assertSame([], $work->drops);
    }

    public function testAPendingRequestOfTheWorkDoesNotMoveTheShutdownDeadlineAndIsReportedAsGivenUp(): void
    {
        $this->channel->complete = false; // the API never answers
        $queue = $this->queue(finishes: true);
        $work = new RecordingDeferredWork(function (): void {
            $this->channel->post(new HttpRequest('https://example.test/', [], '{}', true), static function (): void {});
        });
        $work->leaves = $left = new RecordingOutstanding();
        $queue->defer('a', $work);
        $queue->responseSent();

        $queue->finalShutdown();

        $this->assertCount(1, $this->channel->requests);
        $this->assertSame(EventQueue::SHUTDOWN_TIMEOUT_SECONDS, $this->transport->now(), 'the one 5 s deadline of the events');
        $this->assertSame(['the delivery time ended before the AgentCat API answered'], $left->abandons);
    }

    public function testEventsThatUseTheWholeShutdownTimeLeaveNoneForTheWork(): void
    {
        $this->transport->complete = false; // the events are never delivered
        $queue = $this->queue(finishes: true);
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
        $queue = $this->queue(finishes: true);
        $companion = new RecordingCompanion();
        $queue->attach($companion);
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);
        $queue->responseSent();

        $queue->finalShutdown();

        $this->assertSame([4.5], $work->runs);
        $this->assertContains('finish', $companion->calls);
    }

    public function testWithACompanionAndWithoutTheResponseTheShutdownRunsNoWork(): void
    {
        $queue = $this->queue(finishes: true);
        $queue->attach(new RecordingCompanion());
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->finalShutdown();

        $this->assertSame([], $work->runs);
    }

    public function testAQueueThatIsShuttingDownDropsNewWorkAtOnce(): void
    {
        $queue = $this->queue(finishes: true);
        $queue->shutdown();
        $work = new RecordingDeferredWork();
        $this->assertFalse($queue->canDefer());

        $queue->defer('a', $work);

        $this->assertSame(['the queue is shutting down'], $work->drops);
        $this->assertFalse($queue->hasWork());
    }

    public function testWorkThatThrowsStopsNeitherTheOtherWorkNorTheQueue(): void
    {
        $queue = $this->worker();
        $failing = new RecordingDeferredWork(failure: new \RuntimeException('boom'));
        $next = new RecordingDeferredWork();
        $queue->defer('a', $failing);
        $queue->defer('b', $next);
        $queue->add($this->event('evt_1'));

        $queue->endRequest(1.0);
        $this->assertSame([[1.0], [1.0]], [$failing->runs, $next->runs]);
        $this->assertCount(1, $this->transport->deliveries);

        $queue->defer('c', $failing);
        $queue->defer('d', $next);
        $queue->shutdown();
        $this->assertSame([['the process is ending'], ['the process is ending']], [$failing->drops, $next->drops]);

        $queue->defer('e', $failing); // dropped at once, and its error stays inside
        $this->assertCount(2, $failing->drops);
    }

    public function testWhatIsOnItsWayAndFailsStopsNeitherThePassesNorTheShutdown(): void
    {
        $queue = $this->worker();
        $work = new RecordingDeferredWork();
        $work->leaves = $failing = new RecordingOutstanding(new \LogicException('no state'));
        $other = new RecordingDeferredWork();
        $other->leaves = $left = new RecordingOutstanding();
        $queue->defer('a', $work);
        $queue->defer('b', $other);

        $queue->endRequest(1.0);
        $queue->pump();
        $queue->shutdown();

        $this->assertSame([], $failing->abandons, 'one whose state cannot be read counts as settled');
        $this->assertSame(['the delivery time ended before the AgentCat API answered'], $left->abandons);

        $again = $this->worker();
        $throws = new class implements \AgentCat\Internal\Delivery\Outstanding {
            #[\Override]
            public function settled(): bool
            {
                return false;
            }

            #[\Override]
            public function abandon(string $reason): void
            {
                throw new \LogicException('no log');
            }
        };
        $first = new RecordingDeferredWork();
        $first->leaves = $throws;
        $second = new RecordingDeferredWork();
        $second->leaves = $last = new RecordingOutstanding();
        $again->defer('a', $first);
        $again->defer('b', $second);
        $again->endRequest(1.0);

        $again->shutdown();

        $this->assertCount(1, $last->abandons, 'the error of one stays inside, and the next one is told');
    }

    public function testAWindowWhoseClockFailsRunsNoWork(): void
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
                return ++$this->reads <= 2 ? 0.0 : throw new \RuntimeException('no clock');
            }
        };
        $queue = new EventQueue(transport: $transport, channel: $this->channel, finishesRequests: false);
        $queue->endRequest(1.0); // the first read of the clock
        $work = new RecordingDeferredWork();
        $queue->defer('a', $work);

        $queue->endRequest(1.0); // the second read; the pass cannot read the time left

        $this->assertSame([], $work->runs);
        $this->assertSame(['the request-end window ended before the events were delivered'], $work->drops);
    }

    public function testWorkThatDefersMoreWorkLeavesItForTheEndOfTheWindowWhichDropsIt(): void
    {
        $queue = $this->worker();
        $later = new RecordingDeferredWork();
        $queue->defer('a', new RecordingDeferredWork(static function () use ($queue, $later): void {
            $queue->defer('a', $later);
        }));

        $queue->endRequest(1.0);

        $this->assertSame(1, count($later->runs) + count($later->drops), 'it ran in a later pass of the window, or the window dropped it');
        $this->assertFalse($queue->hasWork());
    }

    public function testTheBaseUrlFollowsTheLastConfigure(): void
    {
        $queue = $this->queue(finishes: true);
        $this->assertStringStartsWith('http://127.0.0.1:', $queue->baseUrl(), 'the guard of the test process');

        $queue->configure('https://custom.example.test/base/');

        $this->assertSame('https://custom.example.test/base', $queue->baseUrl());
    }

    private function queue(bool $finishes): EventQueue
    {
        return new EventQueue(transport: $this->transport, channel: $this->channel, finishesRequests: $finishes);
    }

    /** A worker whose framework bridge already ended one request in a window. */
    private function worker(): EventQueue
    {
        $queue = $this->queue(finishes: false);
        $queue->endRequest(1.0);

        return $queue;
    }

    private function event(string $id): QueuedEvent
    {
        return new QueuedEvent(new Event(id: $id, projectId: 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa', eventType: 'mcp:tools/call'));
    }
}
