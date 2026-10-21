<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\RepeatedLines;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\ApiBaseUrl;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\Transport;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\MemoryLogger;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;

/** A failure that repeats on every pass of a drain logs one line, and one more with the count when the drain ends. */
final class RepeatedFailureTest extends TestCase
{
    private const string GAVE_UP = 'Failed to deliver AgentCat event after retries: ';

    private const float FLUSH_SECONDS = 0.3;

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        RepeatedLines::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        RepeatedLines::reset();
        Log::reset();
    }

    #[MaximumDuration(2000)]
    public function testATransportThatThrowsOnEveryPassLogsTwoLinesPerDrain(): void
    {
        $transport = new class implements Transport {
            public int $pumps = 0;

            #[\Override]
            public function start(Delivery $delivery): void {}

            #[\Override]
            public function pump(): void
            {
                ++$this->pumps;

                throw new \RuntimeException('boom');
            }

            #[\Override]
            public function waitForActivity(float $deadline): bool
            {
                return true;
            }

            #[\Override]
            public function drain(float $deadline): bool
            {
                return false;
            }

            #[\Override]
            public function send(Delivery $delivery, float $deadline = INF): bool
            {
                return false;
            }

            #[\Override]
            public function inFlight(): int
            {
                return 1;
            }

            #[\Override]
            public function now(): float
            {
                return hrtime(true) / 1e9;
            }
        };
        $queue = new EventQueue(transport: $transport);
        $queue->add($this->queued());

        $this->assertFalse($queue->flush(self::FLUSH_SECONDS));

        $this->assertGreaterThan(2, $transport->pumps, 'the drain threw on many passes');
        $this->assertSame([
            self::GAVE_UP . 'RuntimeException: boom',
            sprintf('%sRuntimeException: boom (%d more times in this delivery)', self::GAVE_UP, $transport->pumps - 1),
        ], $this->logger->messages());
    }

    /** The real transport: a curl call that throws on every pass (here the test seam of curl_multi_exec()). */
    #[MaximumDuration(2000)]
    public function testCurlTransportWorkThatThrowsOnEveryPassLogsTwoLinesPerDrain(): void
    {
        $passes = new \ArrayObject();
        $transport = new CurlTransport(multiStatus: static function (string $function, int $code) use ($passes): int {
            if ($function === 'curl_multi_exec') {
                $passes[] = $code;

                throw new \RuntimeException('exec boom');
            }

            return $code;
        });
        $queue = new EventQueue(transport: $transport);
        $queue->configure(MockCollector::closedPortUrl());
        $queue->add($this->queued());

        $this->assertFalse($queue->flush(self::FLUSH_SECONDS));

        $this->assertGreaterThan(2, count($passes), 'the drain threw on many passes');
        $this->assertSame([
            self::GAVE_UP . 'RuntimeException: exec boom',
            sprintf('%sRuntimeException: exec boom (%d more times in this delivery)', self::GAVE_UP, count($passes) - 1),
        ], $this->logger->messages());
    }

    /** The transport's own drain (`send()`) collapses its repeated failure too. */
    #[MaximumDuration(2000)]
    public function testTheTransportsOwnDrainLogsTwoLines(): void
    {
        $passes = new \ArrayObject();
        $transport = new CurlTransport(multiStatus: static function (string $function, int $code) use ($passes): int {
            if ($function === 'curl_multi_exec') {
                $passes[] = $code;

                throw new \RuntimeException('exec boom');
            }

            return $code;
        });
        $event = $this->queued()->event;
        $delivery = Delivery::forEvent($event, ApiBaseUrl::endpoint(MockCollector::closedPortUrl()), WireEncoder::encode($event));

        $this->assertFalse($transport->send($delivery, $transport->now() + self::FLUSH_SECONDS));

        $this->assertGreaterThan(2, count($passes), 'the drain threw on many passes');
        $this->assertSame([
            self::GAVE_UP . 'RuntimeException: exec boom',
            sprintf('%sRuntimeException: exec boom (%d more times in this delivery)', self::GAVE_UP, count($passes) - 1),
        ], $this->logger->messages());
    }

    /** `drain()` on its own, after a `start()` whose first step went well, is one delivery as well. */
    #[MaximumDuration(2000)]
    public function testTheTransportsDrainAloneLogsTwoLines(): void
    {
        $passes = new \ArrayObject();
        $transport = new CurlTransport(multiStatus: static function (string $function, int $code) use ($passes): int {
            if ($function === 'curl_multi_exec') {
                $passes[] = $code;
                if (count($passes) > 1) {
                    throw new \RuntimeException('exec boom');
                }
            }

            return $code;
        });
        $event = $this->queued()->event;
        $transport->start(Delivery::forEvent($event, ApiBaseUrl::endpoint(MockCollector::closedPortUrl()), WireEncoder::encode($event)));
        $this->assertSame([], $this->logger->messages(), 'the first step went well');

        $this->assertFalse($transport->drain($transport->now() + self::FLUSH_SECONDS));

        $this->assertGreaterThan(3, count($passes), 'the drain threw on many passes');
        $this->assertSame([
            self::GAVE_UP . 'RuntimeException: exec boom',
            sprintf('%sRuntimeException: exec boom (%d more times in this delivery)', self::GAVE_UP, count($passes) - 2),
        ], $this->logger->messages());
    }

    private function queued(): QueuedEvent
    {
        return new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'repeated'));
    }
}
