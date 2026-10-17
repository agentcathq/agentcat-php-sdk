<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Client;
use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Tests\Support\Autowire;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/** How a dependency-injection container builds a `Client`. */
final class ClientTest extends TestCase
{
    private Environment $environment;

    private MemoryLogger $logger;

    private EventQueue $global;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_ENABLED', 'APP_ENV', 'AGENTCAT_API_URL', 'MCPCAT_API_URL');
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->global = new EventQueue(transport: new RecordingTransport());
        EventQueue::setGlobal($this->global);
    }

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetSkipped(); // a track() while AgentCat is off marks the process
        EventQueue::setGlobal(null);
        Log::reset();
        $this->environment->restore();
    }

    public function testAnAutowiredClientSendsThroughTheProcessWideQueue(): void
    {
        $client = Autowire::make(Client::class);

        $client->shutdown();
        $this->global->add(new QueuedEvent(new Event(id: 'evt_1', projectId: 'proj_x')));

        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }

    public function testAnAutowiredClientIsOffUnderPhpUnit(): void
    {
        $client = Autowire::make(Client::class);
        $object = new \stdClass();

        $this->assertSame($object, $client->track($object, 'proj_x'));
        $this->assertSame([], $this->logger->messages());
    }

    public function testANewClientSendsThroughTheProcessWideQueue(): void
    {
        $client = new Client();

        $client->shutdown();
        $this->global->add(new QueuedEvent(new Event(id: 'evt_1', projectId: 'proj_x')));

        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }
}
