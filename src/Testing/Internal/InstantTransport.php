<?php

declare(strict_types=1);

namespace AgentCat\Testing\Internal;

use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\Transport;

/**
 * The transport of `AgentCat::fake()`: a delivery runs its exporters once and completes at once. Nothing is sent.
 *
 * @internal
 */
final class InstantTransport implements Transport
{
    #[\Override]
    public function start(Delivery $delivery): void
    {
        if ($delivery->beforeAttempt instanceof \Closure) {
            ($delivery->beforeAttempt)();
        }
    }

    #[\Override]
    public function pump(): void {}

    #[\Override]
    public function waitForActivity(float $deadline): void {}

    #[\Override]
    public function drain(float $deadline): bool
    {
        return true;
    }

    #[\Override]
    public function send(Delivery $delivery, float $deadline = INF): bool
    {
        $this->start($delivery);

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
        return hrtime(true) / 1e9;
    }
}
