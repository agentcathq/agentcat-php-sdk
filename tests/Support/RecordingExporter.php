<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Event;
use AgentCat\Internal\Delivery\ExporterSink;

/** Records every exported event; with `$failure` set, throws it after recording. */
final class RecordingExporter implements ExporterSink
{
    /** @var list<Event> */
    public array $events = [];

    public function __construct(
        public ?\Throwable $failure = null,
    ) {}

    #[\Override]
    public function export(Event $event): void
    {
        $this->events[] = $event;
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
    }
}
