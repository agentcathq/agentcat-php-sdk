<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\ServerInfoRecorder;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server;
use PHPUnit\Framework\TestCase;

/** While `AgentCat::fake()` is active, no server info heartbeat starts: a fake sends nothing over the network. */
final class ServerInfoUnderTheFakeTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ServerInfoRecorder::remove(); // the real heartbeat: the fake itself must keep it off
        Heartbeat::setClock(static fn(): float => 1_000_000.0);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Heartbeat::setClock(null);
        ServerInfoRecorder::install();
        AgentCat::setClient(null);
        EventQueue::setGlobal(null);
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        Log::reset();
    }

    public function testUnderTheFakeAListRequestAndAToolCallStartNoHeartbeat(): void
    {
        $fake = AgentCat::fake();
        $resources = new CustomerHandler(ListResourcesRequest::class, static fn(): ListResourcesResult => new ListResourcesResult([]));
        $builder = Server::builder()->setServerInfo('faked-server', '1.0.0')->setSession()
            ->addTool(static fn(): string => 'alpha ran', 'alpha')
            ->addRequestHandler($resources);
        AgentCat::track($builder, 'proj_' . str_repeat('f', 27));
        $fake->client()->endRequest(1.0); // a worker whose framework bridge ends each request in a window
        $harness = new HttpHarness($builder->build());
        $harness->initialize();

        $this->assertCount(2, JsonAssert::list($harness->listTools()->tools));
        $harness->callTool('alpha', []);
        $harness->callTool('get_more_tools', ['context' => 'a tool that sums']);
        $this->assertObjectHasProperty('result', $harness->request('resources/list'));
        $fake->client()->endRequest(1.0); // the window of a framework bridge
        $fake->client()->flush();
        $fake->client()->shutdown();

        $this->assertSame(1, $resources->calls, 'the list handler ran for the request of the client only');
        $this->assertCount(2, $fake->events());
        $this->assertSame([], array_values(array_filter($fake->logs(), static fn(string $line): bool => str_contains($line, 'heartbeat'))));
    }
}
