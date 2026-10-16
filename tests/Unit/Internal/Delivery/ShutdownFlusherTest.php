<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ShutdownFlusher;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ShutdownFlusherTest extends TestCase
{
    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private EventQueue $queue;

    /** @var list<\Closure(): void> */
    private array $registered = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->queue = new EventQueue(transport: $this->transport);
    }

    #[\Override]
    protected function tearDown(): void
    {
        EventQueue::setGlobal(null);
        Log::reset();
    }

    #[Group('ac-03-12')]
    public function testRegistersTheShutdownFunctionOnce(): void
    {
        $flusher = $this->flusher();

        $flusher->ensureRegistered();
        $flusher->ensureRegistered();

        $this->assertCount(1, $this->registered);
        $this->assertSame('onShutdown', $this->functionName($this->registered[0]));
    }

    public function testRegistersAgainAfterARegistrarFailure(): void
    {
        $attempts = 0;
        $flusher = new ShutdownFlusher(
            fn(): EventQueue => $this->queue,
            static function (\Closure $callback) use (&$attempts): void {
                if (++$attempts === 1) {
                    throw new \RuntimeException('nope');
                }
            },
        );

        try {
            $flusher->ensureRegistered();
            self::fail('the registrar error reaches the queue, which logs it');
        } catch (\RuntimeException) {
        }
        $flusher->ensureRegistered();
        $flusher->ensureRegistered();

        $this->assertSame(2, $attempts);
    }

    #[Group('ac-03-12')]
    public function testFirstPassRegistersTheFinalFlush(): void
    {
        $flusher = $this->flusher();

        $flusher->onShutdown();

        $this->assertCount(1, $this->registered);
        $this->assertSame('onFinalShutdown', $this->functionName($this->registered[0]));
    }

    public function testFirstPassFlushesAtOnceWhenItCannotRegister(): void
    {
        $this->queue->add(new QueuedEvent($this->event()));
        $flusher = new ShutdownFlusher(
            fn(): EventQueue => $this->queue,
            static fn(\Closure $callback): never => throw new \RuntimeException('nope'),
        );

        $flusher->onShutdown();

        $this->assertCount(1, $this->transport->deliveries);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-03-12')]
    public function testFinalPassFinishesTheRequestThenShutsTheQueueDown(): void
    {
        $this->queue->add(new QueuedEvent($this->event()));
        $deliveriesAtFinish = [];
        $flusher = $this->flusher(function () use (&$deliveriesAtFinish): void {
            $deliveriesAtFinish[] = count($this->transport->deliveries);
        });

        $flusher->onFinalShutdown();

        $this->assertSame([0], $deliveriesAtFinish);
        $this->assertCount(1, $this->transport->deliveries);
        $this->queue->add(new QueuedEvent($this->event()));
        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }

    public function testFinalPassFinishesTheRequestForInFlightDeliveries(): void
    {
        $transport = new RecordingTransport(complete: false);
        $queue = new EventQueue(transport: $transport, shutdownTimeoutSeconds: 0.0);
        $queue->add(new QueuedEvent($this->event()));
        $queue->pump();
        $finished = 0;
        $flusher = new ShutdownFlusher(static fn(): EventQueue => $queue, $this->recorder(), static function () use (&$finished): void {
            ++$finished;
        });

        $flusher->onFinalShutdown();

        $this->assertSame(['queueLength' => 0, 'activeRequests' => 1], $queue->stats());
        $this->assertSame(1, $finished);
    }

    public function testFinalPassWithAnEmptyQueueDoesNotFinishTheRequest(): void
    {
        $finished = 0;
        $flusher = $this->flusher(static function () use (&$finished): void {
            ++$finished;
        });

        $flusher->onFinalShutdown();

        $this->assertSame(0, $finished);
        $this->queue->add(new QueuedEvent($this->event()));
        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }

    public function testFinalPassWithoutFinishRequestShutsTheQueueDown(): void
    {
        $this->queue->add(new QueuedEvent($this->event()));

        $this->flusher()->onFinalShutdown();

        $this->assertCount(1, $this->transport->deliveries);
    }

    public function testThrowingFinishRequestDoesNotStopTheShutdown(): void
    {
        $this->queue->add(new QueuedEvent($this->event()));
        $flusher = $this->flusher(static fn(): never => throw new \RuntimeException('finish failed'));

        $flusher->onFinalShutdown();

        $this->assertCount(1, $this->transport->deliveries);
        $this->queue->add(new QueuedEvent($this->event()));
        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }

    public function testThrowingQueueLookupNeverReachesTheShutdown(): void
    {
        $flusher = new ShutdownFlusher(static fn(): never => throw new \RuntimeException('no queue'), $this->recorder());

        $flusher->onFinalShutdown();

        $this->assertSame([], $this->logger->messages());
    }

    public function testFlushesTheQueueTheLookupReturnsAtShutdown(): void
    {
        $later = new EventQueue(transport: $this->transport);
        $later->add(new QueuedEvent($this->event()));
        $current = $this->queue;
        $flusher = new ShutdownFlusher(static function () use (&$current): EventQueue {
            return $current;
        }, $this->recorder());
        $current = $later;

        $flusher->onFinalShutdown();

        $this->assertCount(1, $this->transport->deliveries);
    }

    /**
     * @param (\Closure(): void)|null $finishRequest
     */
    private function flusher(?\Closure $finishRequest = null): ShutdownFlusher
    {
        return new ShutdownFlusher(fn(): EventQueue => $this->queue, $this->recorder(), $finishRequest);
    }

    /** @return \Closure(\Closure(): void): void */
    private function recorder(): \Closure
    {
        return function (\Closure $callback): void {
            $this->registered[] = $callback;
        };
    }

    /** @param \Closure(): void $callback */
    private function functionName(\Closure $callback): string
    {
        return (new \ReflectionFunction($callback))->getName();
    }

    private function event(): Event
    {
        return new Event(projectId: 'proj_test', eventType: 'mcp:tools/call');
    }
}
