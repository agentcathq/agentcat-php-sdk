<?php

declare(strict_types=1);

namespace AgentCat\Tests\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Pipeline\QueuedEvent;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// One event for the end of the process, on a PHP whose disable_functions holds the sleep functions. The event API is a
// closed port (AGENTCAT_API_URL), so each attempt fails and schedules a retry: the shutdown delivery waits for it, or,
// without a function that sleeps, ends there. It calls no such function itself.
EventQueue::global()->add(new QueuedEvent(new Event(projectId: 'proj_' . str_repeat('h', 27), eventType: 'mcp:tools/call', resourceName: 'hardened-shutdown')));
