<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\Transport;

/**
 * A transport that records deliveries instead of sending them.
 *
 * `start()` records and holds a delivery; `pump()` completes everything held and runs each `beforeAttempt` once.
 * With `$complete = false` deliveries stay held. Time is simulated: `waitForActivity()` jumps to its deadline,
 * so no test ever sleeps. With `$failure` set, every method throws it.
 */
final class RecordingTransport implements Transport
{
    /** @var list<Delivery> every delivery passed to start(), in order */
    public array $deliveries = [];

    /** Number of waitForActivity() calls. */
    public int $waits = 0;

    /** @var list<Delivery> started and not completed */
    private array $held = [];

    private float $clock = 0.0;

    public function __construct(
        public bool $complete = true,
        public ?\Throwable $failure = null,
    ) {}

    #[\Override]
    public function start(Delivery $delivery): void
    {
        $this->fail();
        $this->deliveries[] = $delivery;
        $this->held[] = $delivery;
    }

    #[\Override]
    public function pump(): void
    {
        $this->fail();
        if (!$this->complete) {
            return;
        }
        while ($this->held !== []) {
            $delivery = array_shift($this->held);
            if ($delivery->beforeAttempt !== null) {
                ($delivery->beforeAttempt)();
            }
        }
    }

    #[\Override]
    public function waitForActivity(float $deadline): void
    {
        $this->fail();
        ++$this->waits;
        $this->clock = max($this->clock, $deadline);
    }

    #[\Override]
    public function drain(float $deadline): bool
    {
        $this->pump();

        return $this->held === [];
    }

    #[\Override]
    public function send(Delivery $delivery, float $deadline = INF): bool
    {
        $this->start($delivery);

        return $this->drain($deadline);
    }

    #[\Override]
    public function inFlight(): int
    {
        $this->fail();

        return count($this->held);
    }

    #[\Override]
    public function now(): float
    {
        $this->fail();

        return $this->clock;
    }

    private function fail(): void
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
    }
}
