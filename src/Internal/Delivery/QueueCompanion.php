<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * Work that runs beside the events in the queue's passes (diagnostics): `EventQueue::attach()` adds one. Its HTTP
 * requests go through `EventQueue::channel()`. A thrown error is swallowed and never stops the queue.
 *
 * @internal
 */
interface QueueCompanion
{
    /**
     * On every pass of `pump()`, `flush()` and `shutdown()`, except the pass after `finish()`, before the side channel
     * is pumped. Must not block.
     */
    public function tick(): void;

    /**
     * Once per `shutdown()` with time left: after the events and side requests are delivered, or when only the
     * finish reserve of the budget is left (10 %, at most 0.5 s), and before the last wait. May post a last request.
     */
    public function finish(): void;

    /** Whether anything is left to send (buffered lines). */
    public function hasWork(): bool;
}
