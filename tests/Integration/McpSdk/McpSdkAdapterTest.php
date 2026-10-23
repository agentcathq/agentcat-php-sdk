<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Tests\Support\McpSdk\BuilderMembers;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Thrown;
use Mcp\Capability\RegistryInterface;
use Mcp\Server;
use Mcp\Server\Handler\Request\InitializeHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

final class McpSdkAdapterTest extends TestCase
{
    private const string MATRIX = 'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder or the Mcp\Server built from it to AgentCat::track()).';

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    /** @return iterable<string, array{object, bool}> */
    public static function servers(): iterable
    {
        $built = Server::builder();
        $built->build();

        yield 'Builder' => [Server::builder(), true];
        yield 'built Builder' => [$built, true];
        yield 'Server' => [Server::builder()->build(), true];
        yield 'anything else' => [new \stdClass(), false];
    }

    #[DataProvider('servers')]
    public function testABuilderAndAServerAreSupported(object $server, bool $supported): void
    {
        $this->assertSame($supported, McpSdkAdapter::supports($server));
    }

    public function testSignalsNameWhatTheObjectIs(): void
    {
        $builder = Server::builder();
        $this->assertSame(['mcpSdkBuilder'], McpSdkAdapter::signals($builder));

        $server = $builder->build();

        $this->assertSame(['mcpSdkBuilder', 'built'], McpSdkAdapter::signals($builder));
        $this->assertSame(['mcpSdkServer'], McpSdkAdapter::signals($server));
        $this->assertSame([], McpSdkAdapter::signals(new \stdClass()));
    }

    public function testTheVersionIsTheInstalledMcpSdkVersion(): void
    {
        $this->assertSame(SdkVersion::installed('mcp/sdk'), McpSdkAdapter::version());
        $this->assertNotSame('unknown', McpSdkAdapter::version());
    }

    public function testAFreshBuilderPassesInspection(): void
    {
        McpSdkAdapter::inspect(Server::builder());

        $this->assertSame([], $this->logger->messages());
    }

    public function testABuiltBuilderAndABuiltServerPassInspection(): void
    {
        $builder = Server::builder();
        $server = $builder->build();

        McpSdkAdapter::inspect($builder);
        McpSdkAdapter::inspect($server);

        $this->assertSame([], $this->logger->messages());
    }

    public function testABuilderWhoseHandlerListCannotBeReadFailsInspection(): void
    {
        $builder = Server::builder();
        BuilderMembers::uninitialize('requestHandlers')($builder);

        $error = Thrown::by(\UnexpectedValueException::class, static fn() => McpSdkAdapter::inspect($builder));

        $this->assertSame(sprintf('AgentCat SDK compatibility error: mcp/sdk %s internals changed (Error: Typed property Mcp\Server\Builder::$requestHandlers must not be accessed before initialization). %s', McpSdkAdapter::version(), self::MATRIX), $error->getMessage());
    }

    public function testABuiltBuilderWithoutTheLibrarysCallHandlerFailsInspection(): void
    {
        $builder = Server::builder();
        $builder->build();
        $parts = BuilderMembers::reader('parts')($builder);
        $this->assertIsArray($parts);
        $parts['requestHandlers'] = [];
        BuilderMembers::writer('parts')($builder, $parts);

        $error = Thrown::by(\UnexpectedValueException::class, static fn() => McpSdkAdapter::inspect($builder));

        $this->assertSame('AgentCat SDK compatibility error: the mcp/sdk server has no tools/call handler of its own. ' . self::MATRIX, $error->getMessage());
    }

    /** The first `initialize` handler names the server; one without a configuration names none, and nothing drifted. */
    public function testABuiltServerWhoseInitializeHandlerHasNoConfigurationHasNoName(): void
    {
        $server = Server::builder()->setServerInfo('named', '1.0.0')->addRequestHandler(new InitializeHandler())->build();

        $this->assertSame([null, null], McpSdkAdapter::serverInfo($server)());
        $this->assertSame(['named', '1.0.0'], McpSdkAdapter::serverInfo(Server::builder()->setServerInfo('named', '1.0.0')->build())());
        $this->assertSame([], $this->logger->messages());
    }

    public function testAServerWithARegistryOfItsOwnFailsInspection(): void
    {
        $registry = self::createStub(RegistryInterface::class);
        $server = Server::builder()->setRegistry($registry)->build();

        $error = Thrown::by(\UnexpectedValueException::class, static fn() => McpSdkAdapter::inspect($server));

        $this->assertSame('AgentCat SDK compatibility error: the mcp/sdk server has a registry of its own (' . $registry::class . '). ' . self::MATRIX, $error->getMessage());
        $this->assertSame([['level' => LogLevel::WARNING, 'message' => 'AgentCat SDK Compatibility: ' . self::MATRIX]], $this->logger->records);
    }

    public function testTheLoggerIsTheBuildersOwn(): void
    {
        $logger = new NullLogger();

        $this->assertNull(McpSdkAdapter::logger(Server::builder()));
        $this->assertSame($logger, McpSdkAdapter::logger(Server::builder()->setLogger($logger)));
    }

    public function testServerInfoIsReadWhenAnEventIsPublished(): void
    {
        $builder = Server::builder()->setServerInfo('harness-server', '1.2.3');
        $info = McpSdkAdapter::serverInfo($builder);
        $this->assertSame([null, null], $info(), 'not built yet');

        $builder->build();

        $this->assertSame(['harness-server', '1.2.3'], $info());
        $this->assertSame([], $this->logger->messages());
    }
}
