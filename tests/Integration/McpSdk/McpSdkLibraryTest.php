<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\McpSdk\McpSdkLibrary;
use AgentCat\Options;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use Mcp\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpSdkLibraryTest extends TestCase
{
    public function testTheLibraryAnswersForTheBuilder(): void
    {
        $library = new McpSdkLibrary();
        $builder = Server::builder();

        $this->assertTrue($library->supports($builder));
        $this->assertFalse($library->supports(Server::builder()->build()));
        $this->assertSame('mcp-sdk/builder', $library->sdk($builder));
        $this->assertSame('mcp-sdk/server', $library->sdk(Server::builder()->build()));
        $this->assertSame(McpSdkAdapter::SUPPORT_MATRIX, $library->supportMatrix());
        $this->assertSame(['mcpSdkBuilder'], $library->signals($builder));
        $this->assertSame([], $library->signals(new \stdClass()));
    }

    public function testTheLoggerIsTheBuildersOwnAndAnyOtherObjectHasNone(): void
    {
        $library = new McpSdkLibrary();
        $logger = new NullLogger();

        $this->assertSame($logger, $library->logger(Server::builder()->setLogger($logger)));
        $this->assertNull($library->logger(Server::builder()));
        $this->assertNull($library->logger(new \stdClass()));
    }

    public function testServerInfoReadsTheBuilder(): void
    {
        $builder = Server::builder()->setServerInfo('library-server', '2.0.0');
        $info = (new McpSdkLibrary())->serverInfo($builder);

        $builder->build();

        $this->assertSame(['library-server', '2.0.0'], $info());
    }

    public function testTheBuilderStepsRefuseAnyOtherObject(): void
    {
        $library = new McpSdkLibrary();
        $object = new \stdClass();
        $publisher = new Publisher(new EventQueue(transport: new RecordingTransport()));
        $state = new TrackedServer('', new Options(), static fn(): array => [null, null], $publisher);

        $steps = [
            'inspect' => static fn() => $library->inspect($object),
            'serverInfo' => static fn(): \Closure => $library->serverInfo($object),
            'install' => static fn() => $library->install($object, $state, $publisher, new CallInterceptor($publisher)),
        ];
        foreach ($steps as $step => $run) {
            $this->assertSame('Not an mcp/sdk Builder: stdClass', Thrown::by(\UnexpectedValueException::class, $run)->getMessage(), $step);
        }
    }
}
