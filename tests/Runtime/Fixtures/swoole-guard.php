<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Pipeline\QueuedEvent;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// CliTest: whether the first event on the global queue installs AgentCat's SIGTERM handler (plan E1 header conflict
// 14: not when ext-swoole or ext-openswoole is loaded). The queue is internal, hence AgentCat's namespace. An event
// without a project is only exported, and no exporter is set, so the shutdown sends nothing.
$pcntl = function_exists('pcntl_signal_get_handler');
$before = $pcntl ? pcntl_signal_get_handler(SIGTERM) : null;
EventQueue::global()->add(new QueuedEvent(new Event(projectId: '')));
$after = $pcntl ? pcntl_signal_get_handler(SIGTERM) : null;
echo json_encode(['swoole' => extension_loaded('swoole') || extension_loaded('openswoole'), 'pcntl' => $pcntl, 'agentcatHandler' => $after !== $before], JSON_THROW_ON_ERROR);
