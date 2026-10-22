<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\Internal\Engine\Libraries;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\LaravelMcp\Internal\MethodKey;
use AgentCat\LaravelMcp\Internal\TrackedCallTool;
use AgentCat\LaravelMcp\Internal\TrackedInitialize;
use AgentCat\LaravelMcp\Internal\TrackedListTools;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use AgentCat\LaravelMcp\LaravelMcpLibrary;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\DriftedServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use AgentCat\Tests\Support\Members;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Mcp\Server as McpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

final class LaravelMcpAdapterTest extends LaravelTestCase
{
    /** @return iterable<string, array{object, bool}> */
    public static function servers(): iterable
    {
        yield 'a laravel/mcp server' => [new ConformanceServer(new LineTransport()), true];
        yield 'an mcp/sdk Builder' => [McpServer::builder(), false];
        yield 'anything else' => [new \stdClass(), false];
    }

    #[DataProvider('servers')]
    public function testOnlyALaravelMcpServerIsSupported(object $server, bool $supported): void
    {
        $this->assertSame($supported, LaravelMcpAdapter::supports($server));
        $this->assertSame($supported ? ['laravelMcpServer'] : [], LaravelMcpAdapter::signals($server));
    }

    public function testALaravelServerHasTheLaravelLibrary(): void
    {
        $server = $this->conformanceServer();

        $this->assertInstanceOf(LaravelMcpLibrary::class, Libraries::for($server));
        $this->assertSame('laravelMcpServer', Libraries::fingerprint($server));
        $this->assertNull(Libraries::trackedServer($server));
    }

    public function testTheVersionIsTheInstalledLaravelMcpVersion(): void
    {
        $this->assertSame(SdkVersion::installed('laravel/mcp'), LaravelMcpAdapter::version());
        $this->assertNotSame('unknown', LaravelMcpAdapter::version());
    }

    public function testAServerWhoseMembersReadPassesInspection(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        LaravelMcpAdapter::inspect($this->conformanceServer());

        $this->assertSame([], $logger->messages());
    }

    public function testDriftLogsTheCompatibilityLineAndTracksNothing(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $server = $this->app?->make(DriftedServer::class, ['transport' => new LineTransport()]);
        $this->assertInstanceOf(DriftedServer::class, $server);

        $error = Thrown::by(\UnexpectedValueException::class, static fn() => LaravelMcpAdapter::inspect($server));

        $this->assertStringStartsWith('AgentCat SDK compatibility error: laravel/mcp ' . LaravelMcpAdapter::version() . ' internals changed (Error: ', $error->getMessage());
        $this->assertStringEndsWith('). ' . LaravelMcpAdapter::SUPPORT_MATRIX, $error->getMessage());
        $this->assertInstanceOf(\Error::class, $error->getPrevious());
        $this->assertSame(0, $error->getCode());
        $this->assertSame([['level' => LogLevel::WARNING, 'message' => LaravelMcpAdapter::COMPATIBILITY_LINE]], $logger->records);
    }

    public function testTrackOfADriftedServerLogsAndReturnsTheServer(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $client = new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport()), static fn(): bool => true, false));
        $server = $this->app?->make(DriftedServer::class, ['transport' => new LineTransport()]);
        $this->assertInstanceOf(DriftedServer::class, $server);

        $this->assertSame($server, $client->track($server, LaravelTrackingFixture::PROJECT));

        $messages = $logger->messages();
        $this->assertCount(2, $messages);
        $this->assertSame(LaravelMcpAdapter::COMPATIBILITY_LINE, $messages[0]);
        $this->assertStringStartsWith('Warning: Failed to track server - UnexpectedValueException: AgentCat SDK compatibility error: laravel/mcp ', $messages[1]);
    }

    public function testTrackGoesThroughTheLaravelLibrary(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $client = new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport()), static fn(): bool => true, false));
        $server = $this->conformanceServer();

        $this->assertSame($server, $client->track($server, LaravelTrackingFixture::PROJECT));
        $client->track($server, LaravelTrackingFixture::PROJECT);

        $this->assertSame([
            'AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server | signals laravelMcpServer',
            'AgentCat setup complete | project ' . LaravelTrackingFixture::PROJECT . ' | tracing=true context=true reportMissing=true exporters=0',
            'AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server | signals laravelMcpServer',
            '[SESSION DEBUG] track() - Server already being tracked, skipping initialization',
        ], $logger->messages());
        $this->assertSame(MethodKey::for($server, 'tools/list'), ServerAccess::methods($server)['tools/list'] ?? null);
    }

    public function testInstallPointsTheEntriesAtAgentCatsMethodsUnderPerServerKeys(): void
    {
        $server = $this->conformanceServer();
        $other = $this->conformanceServer();
        $fixture = new LaravelTrackingFixture();

        $fixture->track($server);
        $fixture->track($other);

        $methods = ServerAccess::methods($server);
        $this->assertSame('agentcat.laravel-mcp.' . spl_object_id($server) . '.tools/list', $methods['tools/list'] ?? null);
        $this->assertSame('agentcat.laravel-mcp.' . spl_object_id($server) . '.initialize', $methods['initialize'] ?? null);
        $this->assertSame('agentcat.laravel-mcp.' . spl_object_id($server) . '.tools/call', $methods['tools/call'] ?? null);
        $this->assertInstanceOf(TrackedListTools::class, Container::getInstance()->make(MethodKey::for($server, 'tools/list')));
        $this->assertInstanceOf(TrackedCallTool::class, Container::getInstance()->make(MethodKey::for($server, 'tools/call')));
        $this->assertInstanceOf(TrackedInitialize::class, Container::getInstance()->make(MethodKey::for($server, 'initialize')));
        $this->assertNotSame(Container::getInstance()->make(MethodKey::for($server, 'tools/list')), Container::getInstance()->make(MethodKey::for($other, 'tools/list')));
        $this->assertSame(MethodKey::for($other, 'tools/list'), ServerAccess::methods($other)['tools/list'] ?? null);
        $this->assertSame([], $fixture->lines());
    }

    public function testEntriesThatNameNoMethodClassAreLeftAsTheyAre(): void
    {
        $server = new class (new LineTransport()) extends Server {
            public function __construct(Transport $transport)
            {
                parent::__construct($transport);
                // @phpstan-ignore argument.type (a container key, as another package could bind)
                $this->addMethod('tools/list', 'customer.list');
                // @phpstan-ignore argument.type (see above)
                $this->addMethod('tools/call', 'customer.call');
                unset($this->methods['initialize']);
            }
        };

        (new LaravelTrackingFixture())->track($server);

        $methods = ServerAccess::methods($server);
        $this->assertSame('customer.list', $methods['tools/list'] ?? null);
        $this->assertSame('customer.call', $methods['tools/call'] ?? null);
        $this->assertArrayNotHasKey('initialize', $methods);
    }

    public function testACustomerInitializeClassIsWrapped(): void
    {
        $server = new class (new LineTransport()) extends Server {
            public function __construct(Transport $transport)
            {
                parent::__construct($transport);
                $this->addMethod('initialize', RecordingMethod::class);
            }
        };

        (new LaravelTrackingFixture())->track($server);

        $method = Container::getInstance()->make(MethodKey::for($server, 'initialize'));
        $this->assertInstanceOf(TrackedInitialize::class, $method);
        $this->assertSame(MethodKey::for($server, 'initialize'), ServerAccess::methods($server)['initialize'] ?? null);
    }

    public function testTheServerNameAndVersionComeFromTheServer(): void
    {
        $server = $this->conformanceServer();

        $this->assertSame(['conformance-server', '2.0.0'], (LaravelMcpAdapter::serverInfo($server))());
    }

    public function testTheServerInfoHoldsTheServerWeakly(): void
    {
        $server = $this->conformanceServer();
        $info = LaravelMcpAdapter::serverInfo($server);

        unset($server);

        $this->assertSame([null, null], $info());
    }

    /**
     * `createContext()` is public customer code, not a protected-member read (Task 5 review minor 3): what it throws
     * leaves the name and version unknown and logs nothing; a deprecation it triggers costs nothing.
     */
    public function testAServerInfoThatCannotBeReadIsUnknownWithoutALine(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $server = new class (new LineTransport()) extends Server {
            #[\Override]
            public function createContext(): ServerContext
            {
                throw new \RuntimeException('no context');
            }
        };
        $deprecated = new class (new LineTransport()) extends Server {
            #[\Override]
            public function createContext(): ServerContext
            {
                @trigger_error('deprecated context', E_USER_DEPRECATED);

                return parent::createContext();
            }
        };

        $this->assertSame([null, null], (LaravelMcpAdapter::serverInfo($server))());
        $this->assertSame(['Laravel MCP Server', '0.0.1'], (LaravelMcpAdapter::serverInfo($deprecated))(), 'a deprecation is no failure');
        $this->assertSame([], $logger->records);
    }

    #[Group('ac-02-43')]
    public function testTheHttpContextIsTheRequestOfTheServersOwnTransport(): void
    {
        $request = Request::create('/mcp/weather?unit=c', 'POST', server: ['HTTP_HOST' => 'example.test', 'HTTP_X_TRACE' => 't1']);
        $server = $this->conformanceServer(new HttpTransport($request));

        $http = (LaravelMcpAdapter::httpContext($server))();

        $this->assertInstanceOf(HttpContext::class, $http);
        $this->assertSame('POST', $http->method);
        $this->assertSame('http://example.test/mcp/weather?unit=c', $http->url);
        $this->assertSame('t1', $http->headers['x-trace'] ?? null);
        $this->assertSame('example.test', $http->headers['host'] ?? null);
    }

    /**
     * Symfony refuses an invalid method override, which the client controls: no HTTP context, and no compatibility line
     * (Task 5 review minor 2).
     */
    public function testARequestLaravelCannotReadHasNoHttpContextAndLogsNothing(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $server = $this->conformanceServer(new HttpTransport(Request::create('/mcp', 'POST', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'NOT A METHOD'])));

        $this->assertNull((LaravelMcpAdapter::httpContext($server))());
        $this->assertSame([], $logger->records);
    }

    public function testAServerOnAnotherTransportOrGoneHasNoHttpContext(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $stdio = LaravelMcpAdapter::httpContext($this->conformanceServer());
        $server = $this->conformanceServer(new HttpTransport(Request::create('/mcp', 'POST')));
        $gone = LaravelMcpAdapter::httpContext($server);
        unset($server);

        $this->assertNull($stdio());
        $this->assertNull($gone());
        $this->assertSame([], $logger->messages());
    }

    /** @return iterable<string, array{\Closure(Server): void}> */
    public static function unreadableTransports(): iterable
    {
        yield 'the server\'s transport' => [static function (Server $server): void {
            Members::uninitialize(Server::class, 'transport')($server);
        }];
        yield 'the transport\'s request' => [static function (Server $server): void {
            Members::uninitialize(HttpTransport::class, 'request')(ServerAccess::transport($server));
        }];
    }

    /**
     * A drifted transport costs the HTTP context, never the event: one line per server (plan E1 Task 4 carry, D32).
     *
     * @param \Closure(Server): void $drift
     */
    #[DataProvider('unreadableTransports')]
    public function testAnUnreadableTransportIsNoHttpContextAndLoggedOncePerServer(\Closure $drift): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $server = $this->conformanceServer(new HttpTransport(Request::create('/mcp', 'POST')));
        $drift($server);
        $http = LaravelMcpAdapter::httpContext($server);

        $this->assertNull($http());
        $this->assertNull($http());
        $this->assertSame([['level' => LogLevel::WARNING, 'message' => LaravelMcpAdapter::COMPATIBILITY_LINE]], $logger->records);
    }

    private function conformanceServer(Transport $transport = new LineTransport()): ConformanceServer
    {
        $server = $this->app?->make(ConformanceServer::class, ['transport' => $transport]);
        $this->assertInstanceOf(ConformanceServer::class, $server);

        return $server;
    }
}
