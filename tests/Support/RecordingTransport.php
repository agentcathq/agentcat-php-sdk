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
 * so no test ever sleeps. With `$canWait = false` it cannot block, as on a host without a sleep function: it returns
 * false, and the clock moves on by `SPIN_SECONDS`, the time of a pass of a loop that spins. With `$failure` set, every
 * method throws it.
 */
final class RecordingTransport implements Transport
{
    /** @var list<Delivery> every delivery passed to start(), in order */
    public array $deliveries = [];

    /** What one waitForActivity() costs on the simulated clock when it cannot block. */
    public const float SPIN_SECONDS = 0.001;

    /** Number of waitForActivity() calls. */
    public int $waits = 0;

    /** @var list<Delivery> started and not completed */
    private array $held = [];

    private float $clock = 0.0;

    public function __construct(
        public bool $complete = true,
        public ?\Throwable $failure = null,
        public bool $canWait = true,
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
    public function waitForActivity(float $deadline): bool
    {
        $this->fail();
        if (is_nan($deadline)) {
            throw new \LogicException('waitForActivity() got a NaN deadline'); // a deadline that never passes
        }
        ++$this->waits;
        if (!$this->canWait) {
            $this->clock += self::SPIN_SECONDS;

            return false;
        }
        $this->clock = max($this->clock, $deadline);

        return true;
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
