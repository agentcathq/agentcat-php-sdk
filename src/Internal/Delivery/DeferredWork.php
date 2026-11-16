<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * Work that the queue runs once, after the events, where the client of its request already has the response
 * (`EventQueue::defer()`). Its HTTP requests go through `EventQueue::channel()`. A thrown error is swallowed and never
 * stops the queue.
 *
 * @internal
 */
interface DeferredWork
{
    /**
     * Runs the work. It blocks the delivery while it runs, so it starts nothing once `$secondsLeft` have passed.
     *
     * @param float $secondsLeft what is left of the delivery that runs it; INF in a `pump()`, which has no deadline
     */
    public function run(float $secondsLeft): void;

    /** The queue gave the work up, and `run()` will not be called; `$reason` says why, as the end of a log line. */
    public function drop(string $reason): void;
}
