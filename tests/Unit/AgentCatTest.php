<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\CustomEvent;
use AgentCat\Event;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Libraries;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;

final class AgentCatTest extends TestCase
{
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
        $this->client = new Client(ClientSeams::of($this->queue, static fn(): bool => true));
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete(); // a failed track() marks the process
        TrackedServers::resetSkipped(); // so does a track() while AgentCat is off
        AgentCat::setClient(null);
        EventQueue::setGlobal(null); // N9: the default client of setClient(null) builds the global queue
        Log::reset();
        $this->environment->restore();
    }

    public function testTheStaticClassCannotBeInstantiated(): void
    {
        $this->assertFalse((new \ReflectionClass(AgentCat::class))->isInstantiable());
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
            'AgentCat SDK Compatibility: unrecognized server shape | signals (none). ' . Libraries::supportMatrix(),
            'Warning: Failed to track server - UnexpectedValueException: AgentCat SDK compatibility error: server object does not match any supported MCP SDK shape. ' . Libraries::supportMatrix(),
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

    public function testPublishCustomEventGoesThroughTheClient(): void
    {
        AgentCat::setClient($this->client);

        AgentCat::publishCustomEvent('ses_1', 'proj_x', new CustomEvent(resourceName: 'r'));

        $this->assertTrue(AgentCat::flush());
        $this->assertCount(1, $this->transport->deliveries);
        $body = Json::decode($this->transport->deliveries[0]->body);
        $this->assertSame(['ses_1', 'proj_x', 'r'], [JsonAssert::at($body, 'session_id'), JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'resource_name')]);
        $this->assertSame(["Published custom event for session ses_1 with type 'agentcat:custom'"], $this->logger->messages());
    }

    public function testPublishCustomEventPassesItsValidationErrors(): void
    {
        AgentCat::setClient($this->client);

        $error = Thrown::by(\InvalidArgumentException::class, static fn() => AgentCat::publishCustomEvent(new \stdClass(), 'proj_x'));

        $this->assertSame('Server is not tracked. Please call agentcat.track() first or provide a session ID string.', $error->getMessage());
    }

    public function testShutdownGoesThroughTheClient(): void
    {
        AgentCat::setClient($this->client);

        AgentCat::shutdown();
        $this->queue->add(new QueuedEvent(new Event(id: 'evt_1', projectId: 'proj_x')));

        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }
}
