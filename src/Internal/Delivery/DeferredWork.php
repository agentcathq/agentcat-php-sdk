<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * Work that runs customer code and that the queue runs once, after the events, in a window after the response of the
 * request that deferred it (`EventQueue::defer()`). Its HTTP requests go through `EventQueue::channel()`. A thrown error
 * is swallowed and never stops the queue.
 *
 * @internal
 */
interface DeferredWork
{
    /**
     * Runs the work. It blocks the window while it runs, so it starts nothing once `$secondsLeft` have passed.
     *
     * @param float $secondsLeft what is left of the window that runs it
     */
    public function run(float $secondsLeft): void;

    /** After `run()`: whether something that the work started (a request on the side channel) is still on its way. */
    public function unfinished(): bool;

    /**
     * The queue gave the work up: before `run()`, which will not be called, or after it, when the process ends while
     * what the work started is still on its way. `$reason` says why, as the end of a log line.
     */
    public function drop(string $reason): void;
}
