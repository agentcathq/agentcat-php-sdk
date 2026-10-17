<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Access\ServerAccess;
use AgentCat\McpSdk\Internal\CallToolInterceptor;
use AgentCat\McpSdk\McpSdkAdapter;
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
 * Builder (Ruling R3). The shape of each member is checked with the others in `BuilderMembersTest`.
 */
#[Group('contract')]
final class ServerMembersTest extends TestCase
{
    private TrackingFixture $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture();
    }

    #[\Override]
    protected function tearDown(): void
    {
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
            $this->assertFalse(McpSdkAdapter::trackedServer($server));
            $this->assertFalse(McpSdkAdapter::trackedServer($server));
        }

        $line = ['level' => LogLevel::WARNING, 'message' => McpSdkAdapter::COMPATIBILITY_LINE];
        $this->assertSame([$line, $line], $this->fixture->logger->records);
    }
}
