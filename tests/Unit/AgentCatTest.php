<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class AgentCatTest extends TestCase
{
    private const string MATRIX = 'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

    private Environment $environment;

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private EventQueue $queue;

    private Client $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_ENABLED', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_PROJECT_ID');
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->queue = new EventQueue(transport: $this->transport);
        $this->client = new Client($this->queue, static fn(): bool => true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        AgentCat::setClient(null);
        EventQueue::setGlobal(null); // N9: the default client of setClient(null) builds the global queue
        Log::reset();
        $this->environment->restore();
    }

    public function testSetClientReplacesTheClient(): void
    {
        AgentCat::setClient($this->client);

        $this->assertSame($this->client, AgentCat::client());
    }

    public function testSetClientNullRestoresANewDefaultClient(): void
    {
        AgentCat::setClient($this->client);

        AgentCat::setClient(null);
        $default = AgentCat::client();

        $this->assertNotSame($this->client, $default);
        $this->assertSame($default, AgentCat::client(), 'the default client is created once');
        $object = new \stdClass();
        $this->assertSame($object, AgentCat::track($object, 'proj_x'));
        $this->assertSame([], $this->logger->messages(), 'the default client is off under PHPUnit (D31)');
    }

    public function testTrackGoesThroughTheClient(): void
    {
        AgentCat::setClient($this->client);
        $object = new \stdClass();

        $this->assertSame($object, AgentCat::track($object, 'proj_x'));

        $this->assertSame([
            'AgentCat SDK Compatibility: unrecognized server shape | signals (none). ' . self::MATRIX,
            'Warning: Failed to track server - UnexpectedValueException: AgentCat SDK compatibility error: server object does not match any supported MCP SDK shape. ' . self::MATRIX,
        ], $this->logger->messages());
    }

    public function testFlushGoesThroughTheClient(): void
    {
        AgentCat::setClient($this->client);
        $this->assertTrue(AgentCat::flush(), 'nothing queued');

        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: 'proj_x')));

        $this->assertTrue(AgentCat::flush(1.0));
        $this->assertCount(1, $this->transport->deliveries);
    }

    public function testFlushPassesItsTimeout(): void
    {
        AgentCat::setClient($this->client);
        $this->transport->complete = false;
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: 'proj_x')));

        $this->assertFalse(AgentCat::flush(2.5));
        $this->assertEqualsWithDelta(2.5, $this->transport->now(), PHP_FLOAT_EPSILON, 'the simulated clock stops at the deadline');
    }

    public function testShutdownGoesThroughTheClient(): void
    {
        AgentCat::setClient($this->client);

        AgentCat::shutdown();
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: 'proj_x')));

        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }
}
