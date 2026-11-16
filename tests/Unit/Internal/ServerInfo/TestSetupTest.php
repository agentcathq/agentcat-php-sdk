<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Options;
use AgentCat\Tests\Support\FakeServerInfo;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\ServerInfoRecorder;
use PHPUnit\Framework\TestCase;

/** Guards `tests/bootstrap.php`: in a test process no heartbeat starts by itself, and none can reach the AgentCat API. */
final class TestSetupTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[\Override]
    protected function tearDown(): void
    {
        ServerInfoRecorder::install();
        Log::reset();
    }

    public function testV16TheTestSetupReplacesTheHeartbeatWithARecorderThatSendsNothing(): void
    {
        $this->assertTrue(Heartbeat::replaced(), 'tests/bootstrap.php installed the recorder');
        ServerInfoRecorder::install();
        $channel = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $server = new TrackedServer(self::PROJECT, new Options(), static fn(): array => ['demo-server', '1.2.3'], new Publisher($queue));

        Heartbeat::onToolList($server, static fn(): string => 'demo-server', static fn(): FakeServerInfo => new FakeServerInfo());
        Heartbeat::onToolCall($server, 'alpha', static fn(): string => 'demo-server', static fn(): FakeServerInfo => new FakeServerInfo());

        $this->assertSame([['project' => self::PROJECT, 'always' => true], ['project' => self::PROJECT, 'always' => false]], ServerInfoRecorder::$checks);
        $this->assertFalse($queue->hasWork(), 'the recorder defers nothing');
        $this->assertTrue($queue->flush());
        $this->assertSame([], $channel->requests);
    }

    public function testV16ARealHeartbeatOfATestProcessGoesToAClosedLocalPortNotToTheAgentCatApi(): void
    {
        ServerInfoRecorder::remove();
        Log::setLogger(new MemoryLogger());
        $channel = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $channel);
        $queue->endRequest(1.0); // a worker whose bridge ends each request in a window
        $server = new TrackedServer(self::PROJECT, new Options(), static fn(): array => ['demo-server', '1.2.3'], new Publisher($queue));

        Heartbeat::onToolList($server, static fn(): string => 'demo-server', static fn(): FakeServerInfo => new FakeServerInfo());
        $queue->endRequest(1.0);

        $this->assertCount(1, $channel->requests);
        $this->assertStringStartsWith('http://127.0.0.1:', $channel->requests[0]->url);
        $this->assertStringEndsWith('/v1/public/server-info/', $channel->requests[0]->url);
        $this->assertStringNotContainsString('agentcat.com', $channel->requests[0]->url);
    }
}
