<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Event;

/**
 * Receives each event after the pipeline (redacted, sanitized, truncated): once per API attempt (B03, D15:
 * retries export again), or once when the event has no project ID.
 *
 * Exporters run synchronously, inside `Transport::start()` or `Transport::pump()`. An implementation must return
 * quickly, must not block, and must not call back into the queue or the transport (`flush()`, `pump()`): the
 * queue ignores such re-entrant calls (`flush()` returns false). A thrown error is logged as
 * `Telemetry export error: <error>` and never stops the delivery.
 *
 * @internal
 */
interface ExporterSink
{
    public function export(Event $event): void;
}
