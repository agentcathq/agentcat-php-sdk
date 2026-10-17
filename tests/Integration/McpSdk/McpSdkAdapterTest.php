<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Thrown;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpSdkAdapterTest extends TestCase
{
    private const string MATRIX = 'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

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
        yield 'Server' => [Server::builder()->build(), false];
        yield 'anything else' => [new \stdClass(), false];
    }

    #[DataProvider('servers')]
    public function testOnlyABuilderIsSupported(object $server, bool $supported): void
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

    public function testABuiltBuilderFailsInspection(): void
    {
        $builder = Server::builder();
        $builder->build();

        $error = Thrown::by(\UnexpectedValueException::class, static fn() => McpSdkAdapter::inspect($builder));

        $this->assertSame('AgentCat SDK compatibility error: the mcp/sdk Builder was already built. ' . self::MATRIX, $error->getMessage());
        $this->assertNull($error->getPrevious());
        $this->assertSame(['AgentCat SDK Compatibility: ' . self::MATRIX], $this->logger->messages());
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
