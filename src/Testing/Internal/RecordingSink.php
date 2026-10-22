<?php

declare(strict_types=1);

namespace AgentCat\Testing\Internal;

use AgentCat\Event;
use AgentCat\Internal\Delivery\ExporterSink;

/**
 * Keeps every event the queue exports, in order, for `AgentCatFake`.
 *
 * @internal
 */
final class RecordingSink implements ExporterSink
{
    /** @var list<Event> */
    public array $events = [];

    #[\Override]
    public function export(Event $event): void
    {
        $this->events[] = $event;
    }
}
