<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Pipeline\QueuedEvent;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$baseUrl = $argv[1] ?? '';
$markerFile = $argv[2] ?? '';
$queue = EventQueue::global();
$queue->configure($baseUrl);
$queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'shutdown-delivery')));
// A customer shutdown function registered after ours: ours must still run last.
register_shutdown_function(static function () use ($markerFile): void {
    file_put_contents($markerFile, sprintf('%.6F', microtime(true)));
});
echo "done\n";
