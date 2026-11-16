<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Guard;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\ServerInfoRecorder;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;

/** The server info heartbeat of a symfony/mcp-bundle server that the bundle tracks. */
final class ServerInfoTest extends SymfonyTestCase
{
    private const string PROJECT = 'proj_ddddddddddddddddddddddddddd';

    private const string API = 'https://api.example.test/base';

    private RecordingHttpChannel $channel;

    private float $now = 1000.0;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        ServerInfoRecorder::remove();
        Heartbeat::setClock(fn(): float => $this->now);
        $this->channel = new RecordingHttpChannel();
        EventQueue::setGlobal(new EventQueue(transport: $this->transport = new RecordingTransport(), channel: $this->channel, outlivesRequest: true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        Heartbeat::setClock(null);
        ServerInfoRecorder::install();
        parent::tearDown();
    }

    #[MaximumDuration(1000)]
    public function testV1AndV14OneToolCallSendsTheServerToTheConfiguredApi(): void
    {
        $this->oldMemory();
        $http = $this->http($this->kernel(agentcat: ['enabled' => 'true', 'project_id' => self::PROJECT, 'api_url' => self::API . '/']));

        $answer = $http->callTool('echo', ['msg' => 'hi']);
        $this->assertObjectHasProperty('result', $answer);
        $this->assertHeartbeats(0, 'nothing is sent while the request is answered');
        EventQueue::global()->flush(1.0);

        $this->assertSame([self::API . '/v1/public/server-info/'], $this->channel->urls());
        $body = json_decode($this->heartbeat(0)->body, false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($body));
        $this->assertSame(self::PROJECT, JsonAssert::at($body, 'project_id'));
        $this->assertEquals($http->listTools()->tools, JsonAssert::at($body, 'features', 'tools'), 'the tools are what a client gets from tools/list');
        $this->assertContains('get_more_tools', JsonAssert::column(JsonAssert::at($body, 'features', 'tools'), 'name'));
        $deliveries = $this->deliveries();
        $this->assertCount(1, $deliveries);
        $event = json_decode($deliveries[0]->body, false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $event);
        $this->assertSame([JsonAssert::at($event, 'server_name'), JsonAssert::at($event, 'server_version')], [JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'server_version')], 'V15: the names of the event of the call');
    }

    /** In a worker, the listener of `kernel.terminate` runs the heartbeat: after the response, in the request that started it. */
    #[MaximumDuration(1000)]
    public function testKernelTerminateSendsTheHeartbeatOfTheRequestAfterItsResponse(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        $http = $this->http($kernel);
        $http->initialize();
        $request = $http->callRequest('get_more_tools', ['context' => 'a tool that sums']); // a feedback note: due in a new memory

        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertHeartbeats(0, 'the response is ready, and nothing was read or sent for the heartbeat');
        $kernel->terminate($request, $response);

        $this->assertHeartbeats(1);
        $this->assertStringEndsWith('/v1/public/server-info/', $this->heartbeat(0)->url);
    }

    #[MaximumDuration(1000)]
    public function testV17AndV18WithNoMemoryBetweenRequestsOnlyToolListsSend(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $http = $this->http($this->kernel(agentcat: $this->on(self::PROJECT)), terminate: true);
        $http->initialize();
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $request) {
            Heartbeat::forget(); // the memory of the request before is gone
            $request === 'call'
                ? $this->assertObjectHasProperty('result', $http->callTool('echo', ['msg' => 'hi']))
                : $this->assertNotSame([], $http->listTools()->tools);
        }

        $this->assertHeartbeats(3, implode("\n", $logger->messages()));
    }

    /** How many requests went to the side channel so far. */
    private function assertHeartbeats(int $count, string $message = ''): void
    {
        $this->assertCount($count, $this->channel->requests, $message);
    }

    private function heartbeat(int $index): HttpRequest
    {
        $heartbeats = $this->channel->requests;
        $this->assertArrayHasKey($index, $heartbeats);

        return $heartbeats[$index];
    }

    /** A memory of 60 s, as the rows of the test table have unless they say otherwise. */
    private function oldMemory(): void
    {
        Guard::open(true, $this->now);
        $this->now += Guard::MEMORY_SECONDS;
    }
}
