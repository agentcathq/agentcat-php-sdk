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
    /** On every pass of `pump()`, `flush()` and `shutdown()`, before the side channel is pumped. Must not block. */
    public function tick(): void;

    /** Once per `shutdown()`, after the events are delivered and before the last wait; may post a last request. */
    public function finish(): void;

    /** Whether anything is left to send (buffered lines). */
    public function hasWork(): bool;
}
