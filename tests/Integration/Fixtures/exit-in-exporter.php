<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ExporterSink;
use AgentCat\Internal\Pipeline\QueuedEvent;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// An exporter that calls exit() inside pump(): PHP skips the rest of pump(), so its re-entrancy guard stays set.
// The shutdown delivery must still send the event that waits.
$queue = EventQueue::global();
$queue->configure($argv[1] ?? '');
$queue->setExporterSink(new class implements ExporterSink {
    private bool $exited = false;

    #[\Override]
    public function export(Event $event): void
    {
        if (!$this->exited) {
            $this->exited = true;
            echo "exit\n";
            exit(0);
        }
    }
});
$queue->add(new QueuedEvent(new Event(eventType: 'mcp:tools/call', resourceName: 'telemetry-only')));
$queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'after-exit')));
$queue->pump();
echo "not reached\n";
