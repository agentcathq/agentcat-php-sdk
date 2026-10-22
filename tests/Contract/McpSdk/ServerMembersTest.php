<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use AgentCat\Client;
use AgentCat\CustomEvent;
use AgentCat\Event;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Access\ServerAccess;
use AgentCat\McpSdk\Internal\CallToolInterceptor;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\Thrown;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Protocol;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The members of a built `Mcp\Server` that AgentCat reads to find the tracking state of a server built from a tracked
 * Builder. The shape of each member is checked with the others in `BuilderMembersTest`.
 */
#[Group('contract')]
final class ServerMembersTest extends TestCase
{
    private TrackingFixture $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture();
        TrackedServers::resetIncomplete();
    }

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete();
        Log::reset();
    }

    public function testTheProtocolHoldsTheHandlersBuildAssembled(): void
    {
        $builder = TrackingFixture::builder();
        $server = $builder->build();

        $protocol = ServerAccess::protocol($server);
        $handlers = ServerAccess::requestHandlers($protocol);

        $this->assertSame($protocol, ServerAccess::protocol($server), 'the same protocol on every read');
        $this->assertSame(BuilderAccess::requestHandlers($builder), $handlers);
        $this->assertNotSame([], array_filter($handlers, static fn(mixed $handler): bool => $handler instanceof CallToolHandler));
    }

    public function testATrackedServerHoldsAgentCatsCallHandler(): void
    {
        $builder = TrackingFixture::builder();
        $state = $this->fixture->track($builder);
        $server = $builder->build();

        $handlers = ServerAccess::requestHandlers(ServerAccess::protocol($server));
        $calls = array_values(array_filter($handlers, static fn(mixed $handler): bool => $handler instanceof CallToolInterceptor));

        $this->assertSame([$state], array_map(static fn(CallToolInterceptor $handler): TrackedServer => $handler->server(), $calls));
    }

    public function testTheAdapterFindsAndKeepsTheTrackingStateOfABuiltServer(): void
    {
        $builder = TrackingFixture::builder();
        $state = $this->fixture->track($builder);
        $server = $builder->build();
        $this->assertNull(TrackedServers::get($server));

        $this->assertSame($state, McpSdkAdapter::trackedServer($server));

        $this->assertSame($state, TrackedServers::get($server));
        $this->assertSame([], $this->fixture->lines());
    }

    public function testTheAdapterFindsNoStateForAnUntrackedServerOrAnotherObject(): void
    {
        $this->assertNull(McpSdkAdapter::trackedServer(TrackingFixture::builder()->build()));
        $this->assertNull(McpSdkAdapter::trackedServer(TrackingFixture::builder()));
        $this->assertNull(McpSdkAdapter::trackedServer(new \stdClass()));
        $this->assertSame([], $this->fixture->lines());
        $this->assertFalse(TrackedServers::incomplete(), 'an untracked server is no failure of AgentCat');
    }

    public function testAnUnreadableProtocolThrows(): void
    {
        $server = (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();

        $error = Thrown::by(\Error::class, static fn(): Protocol => ServerAccess::protocol($server));

        $this->assertStringContainsString('Mcp\Server::$protocol must not be accessed before initialization', $error->getMessage());
    }

    public function testUnreadableRequestHandlersThrow(): void
    {
        $protocol = (new \ReflectionClass(Protocol::class))->newInstanceWithoutConstructor();

        $error = Thrown::by(\Error::class, static fn(): array => ServerAccess::requestHandlers($protocol));

        $this->assertStringContainsString('Mcp\Server\Protocol::$requestHandlers must not be accessed before initialization', $error->getMessage());
    }

    public function testDriftIsReportedOncePerServer(): void
    {
        $servers = [
            (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor(),
            new Server((new \ReflectionClass(Protocol::class))->newInstanceWithoutConstructor()),
        ];

        foreach ($servers as $server) {
            $this->assertNull(McpSdkAdapter::trackedServer($server));
            $this->assertNull(McpSdkAdapter::trackedServer($server));
            $this->assertNull(TrackedServers::get($server));
        }

        $line = ['level' => LogLevel::WARNING, 'message' => McpSdkAdapter::COMPATIBILITY_LINE];
        $this->assertSame([$line, $line], $this->fixture->logger->records);
        $this->assertTrue(TrackedServers::incomplete(), 'publishCustomEvent() then logs instead of throwing');
    }

    /** `publishCustomEvent()` on such a server logs and queues nothing, and never throws. */
    public function testPublishingForAServerWhoseInternalsChangedLogsAndReturns(): void
    {
        $server = new Server((new \ReflectionClass(Protocol::class))->newInstanceWithoutConstructor());

        $this->client()->publishCustomEvent($server, TrackingFixture::PROJECT, new CustomEvent(sessionId: 'ses_1'));
        $this->client()->publishCustomEvent($server, TrackingFixture::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $this->fixture->queue->stats());
        $warning = ['level' => LogLevel::WARNING, 'message' => 'Warning: Failed to publish custom event - the server is not tracked (see the earlier AgentCat log line)'];
        $this->assertSame([['level' => LogLevel::WARNING, 'message' => McpSdkAdapter::COMPATIBILITY_LINE], $warning, $warning], $this->fixture->logger->records);
    }

    /** The state kept for a server is used without reading the server again. */
    public function testTheStateKeptForAServerIsUsedFirst(): void
    {
        $server = new Server((new \ReflectionClass(Protocol::class))->newInstanceWithoutConstructor()); // unreadable
        TrackedServers::add($server, new TrackedServer(TrackingFixture::PROJECT, new Options(), static fn(): array => ['kept', '1.0'], $this->fixture->publisher));

        $this->client()->publishCustomEvent($server, TrackingFixture::PROJECT, new CustomEvent(sessionId: 'ses_1'));

        $this->assertSame(['kept'], array_map(static fn(Event $event): ?string => $event->serverName, $this->fixture->events()));
        $this->assertSame(["Published custom event for session ses_1 with type 'agentcat:custom'"], $this->fixture->lines());
    }

    private function client(): Client
    {
        return new Client(ClientSeams::of($this->fixture->queue, static fn(): bool => true));
    }
}
