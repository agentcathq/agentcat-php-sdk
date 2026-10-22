<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

use AgentCat\Event;

/**
 * One configured exporter: it builds its requests for an event and posts them on the side channel.
 *
 * @internal
 */
interface Exporter
{
    /**
     * Never blocks. `$reject` is TypeScript's rejected `export()` promise: the manager logs
     * `Telemetry export failed for <name>: <message>`. An exporter may call it now or from a channel callback; a
     * throwable counts as a rejection with its message.
     *
     * @param \Closure(string): void $reject
     */
    public function export(Event $event, \Closure $reject): void;
}
