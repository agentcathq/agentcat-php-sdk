<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\ApiBaseUrl;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\RetryPolicy;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

/**
 * Guards `tests/bootstrap.php`: in a test process, an event that no test points at its own collector goes to a closed
 * port on 127.0.0.1, never to the AgentCat API, as the diagnostics do.
 */
final class EventApiGuardTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        EventQueue::setGlobal(null);
        Log::reset();
    }

    public function testAnEventWithoutAUrlFailsAtAClosedLocalPort(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $url = ApiBaseUrl::resolve();
        $this->assertStringStartsWith('http://127.0.0.1:', $url); // first: without the guard nothing is sent
        EventQueue::setGlobal(new EventQueue(transport: new CurlTransport(new RetryPolicy([])))); // one attempt, no retry
        EventQueue::global()->add(new QueuedEvent(new Event(id: 'evt_guard', projectId: 'proj_ggggggggggggggggggggggggggg', eventType: 'mcp:tools/call')));

        $this->assertTrue(EventQueue::global()->flush(5.0));

        $lines = implode("\n", $logger->messages());
        $this->assertStringContainsString('Failed to send event evt_guard', $lines);
        $this->assertStringContainsString('127.0.0.1 port ' . parse_url($url, PHP_URL_PORT), $lines);
        $this->assertStringNotContainsString('agentcat.com', $lines);
    }
}
