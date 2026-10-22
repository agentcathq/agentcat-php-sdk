<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\LaravelMcp\Internal\MethodKey;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use AgentCat\LaravelMcp\LaravelMcpLibrary;
use AgentCat\Options;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\DriftedServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;

final class LaravelMcpLibraryTest extends LaravelTestCase
{
    public function testTheLibraryAnswersForALaravelMcpServer(): void
    {
        $library = new LaravelMcpLibrary();
        $server = new ConformanceServer(new LineTransport());

        $this->assertTrue($library->supports($server));
        $this->assertFalse($library->supports(new \stdClass()));
        $this->assertSame('laravel-mcp/server', $library->sdk($server));
        $this->assertSame(LaravelMcpAdapter::SUPPORT_MATRIX, $library->supportMatrix());
        $this->assertSame(['laravelMcpServer'], $library->signals($server));
        $this->assertSame([], $library->signals(new \stdClass()));
    }

    public function testAServerHasNoLoggerAndNoTrackingStateOfItsOwn(): void
    {
        $library = new LaravelMcpLibrary();

        $this->assertNull($library->logger(new ConformanceServer(new LineTransport())));
        $this->assertNull($library->trackedServer(new ConformanceServer(new LineTransport())));
        $this->assertNull($library->trackedServer(new \stdClass()));
    }

    public function testTheServerStepsGoToTheAdapter(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $library = new LaravelMcpLibrary();
        $server = new ConformanceServer(new LineTransport());
        $publisher = new Publisher(new EventQueue(transport: new RecordingTransport()));
        $state = new TrackedServer('', new Options(), static fn(): array => [null, null], $publisher);

        $library->inspect($server);
        $library->install($server, $state, $publisher, new CallInterceptor($publisher));

        $this->assertSame(['conformance-server', '2.0.0'], $library->serverInfo($server)());
        $this->assertSame(MethodKey::for($server, 'tools/list'), ServerAccess::methods($server)['tools/list'] ?? null);
        $this->assertSame([], $logger->messages());
        Thrown::by(\UnexpectedValueException::class, static fn() => $library->inspect(new DriftedServer(new LineTransport())));
        $this->assertSame([LaravelMcpAdapter::COMPATIBILITY_LINE], $logger->messages());
    }

    public function testTheServerStepsRefuseAnyOtherObject(): void
    {
        $library = new LaravelMcpLibrary();
        $object = new \stdClass();
        $publisher = new Publisher(new EventQueue(transport: new RecordingTransport()));
        $state = new TrackedServer('', new Options(), static fn(): array => [null, null], $publisher);

        $steps = [
            'inspect' => static fn() => $library->inspect($object),
            'serverInfo' => static fn(): \Closure => $library->serverInfo($object),
            'install' => static fn() => $library->install($object, $state, $publisher, new CallInterceptor($publisher)),
        ];
        foreach ($steps as $step => $run) {
            $this->assertSame('Not a laravel/mcp Server: stdClass', Thrown::by(\UnexpectedValueException::class, $run)->getMessage(), $step);
        }
    }
}
