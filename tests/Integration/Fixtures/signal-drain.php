<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Pipeline\QueuedEvent;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A long-running CLI server that gets SIGTERM while one event waits in the global queue.
// Usage: signal-drain.php <collector-url> <mode> <marker>
//   default: no SIGTERM handler of its own. AgentCat delivers the event, then SIGTERM ends the process.
//   chain:   its own SIGTERM handler first. AgentCat delivers the event, then calls that handler, which writes
//            "customer" and the time to <marker>; the script goes on and prints "done".
$baseUrl = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$markerFile = $argv[3] ?? '';
if ($mode === 'chain') {
    pcntl_signal(SIGTERM, static function () use ($markerFile): void {
        file_put_contents($markerFile, sprintf("customer\n%.6F", microtime(true)));
    });
}
$queue = EventQueue::global();
$queue->configure($baseUrl);
$queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'signal-drain')));
posix_kill(posix_getpid(), SIGTERM);
if ($mode === 'chain') {
    usleep(200_000);
    echo "done\n";

    exit(0);
}
// A server loop that sleeps: SIGTERM must end it long before the 5 s are over.
for ($step = 0; $step < 100; ++$step) {
    usleep(50_000);
}
