<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * Sends deliveries to the API with retries. No method throws.
 *
 * Deadlines are seconds on the clock of `now()`.
 *
 * @internal
 */
interface Transport
{
    /** Queues the first attempt and starts it if fewer than the maximum transfers run. Never blocks. */
    public function start(Delivery $delivery): void;

    /** Collects finished transfers, logs outcomes, schedules retries by time and starts due ones. Never blocks or sleeps. */
    public function pump(): void;

    /** Blocks until a transfer can progress, a retry is due, or `$deadline`. Does no work. */
    public function waitForActivity(float $deadline): void;

    /** Pumps and waits until nothing is in flight (true) or `$deadline` passes (false). */
    public function drain(float $deadline): bool;

    /** Blocking: `start()`, then `drain()`. True when the delivery settled (sent or given up) before `$deadline`. */
    public function send(Delivery $delivery, float $deadline = INF): bool;

    /** Deliveries waiting to start, transferring, or waiting for a retry. */
    public function inFlight(): int;

    /** Monotonic seconds. */
    public function now(): float;
}
