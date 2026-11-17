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
     * Runs the work. It blocks the window while it runs, so it starts nothing once `$secondsLeft` have passed. When it
     * returns, the queue lets go of the work, and the work has let go of everything of its request.
     *
     * @param float $secondsLeft what is left of the window that runs it
     *
     * @return Outstanding|null what the work left on its way (a request on the side channel), or null
     */
    public function run(float $secondsLeft): ?Outstanding;

    /** The queue gave the work up, and `run()` will not be called; `$reason` says why, as the end of a log line. */
    public function drop(string $reason): void;
}
