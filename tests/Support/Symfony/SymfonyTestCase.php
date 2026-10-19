<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Symfony\Streaming\ProgressTool;
use AgentCat\Tests\Support\ToolCalls;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Symfony applications with symfony/mcp-bundle, for the bundle tests. Skipped where the bundle is not installed (the
 * mcp/sdk main leg removes it). Each kernel compiles its container into a directory of this test under the test HOME,
 * removed after the test (Ruling R8). The AGENTCAT_* variables of the process that runs the tests are cleared, so the
 * configuration decides, and the global event queue records its deliveries.
 */
abstract class SymfonyTestCase extends TestCase
{
    /** The variables a test can set; they are cleared before each test and put back after it. */
    private const array VARIABLES = ['AGENTCAT_ENABLED', 'AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'AGENTCAT_LOG_CHANNEL'];

    protected RecordingTransport $transport;

    /** This test's directory under the test HOME. */
    private string $directory = '';

    /** @var list<TestKernel> the kernels this test booted */
    private array $kernels = [];

    private ?Environment $environment = null;

    #[\Override]
    protected function setUp(): void
    {
        if (!class_exists(McpBundle::class)) {
            self::markTestSkipped('symfony/mcp-bundle is not installed');
        }
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new \LogicException('tests/bootstrap.php sets HOME to the test HOME');
        }
        $this->environment = Environment::clear(...self::VARIABLES);
        TestMode::reset(); // an earlier test can have logged the once-per-process line of AGENTCAT_ENABLED
        $this->directory = $home . '/symfony-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0o700, true)) {
            throw new \RuntimeException('Cannot create ' . $this->directory);
        }
        EventQueue::setGlobal(new EventQueue(transport: $this->transport = new RecordingTransport()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        ToolCalls::reset();
        TestMode::reset();
        BridgeConfig::reset();
        Log::reset();
        Diagnostics::reset();
        EventQueue::setGlobal(null);
        AgentCat::setClient(null);
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();

        $this->environment?->restore();
        FileTree::remove($this->directory);
    }

    /**
     * Boots a kernel with its own directory, so its container is compiled for this configuration.
     *
     * @param array<string, mixed>         $agentcat the agentcat configuration
     * @param array<string, string|object> $services more services: id => class name or instance (synthetic)
     * @param array<string, mixed>|null    $mcp      the mcp configuration; null: the `conformance` server only
     */
    protected function kernel(array $agentcat = [], array $services = [], bool $withAgentCat = true, ?CompilerPassInterface $probe = null, ?array $mcp = null): TestKernel
    {
        $kernel = new TestKernel($this->directory . '/kernel-' . count($this->kernels), $agentcat, $services, $mcp ?? TestKernel::MCP, $withAgentCat, $probe);
        $this->kernels[] = $kernel;
        $handler = $this->exceptionHandler();
        $kernel->boot();
        if ($this->exceptionHandler() !== $handler) {
            restore_exception_handler(); // FrameworkBundle::boot() leaves Symfony's handler with symfony/error-handler 7.3
        }

        return $kernel;
    }

    /**
     * A kernel whose `conformance` server also has the `progress` tool, which answers with an SSE stream.
     *
     * @param array<string, mixed> $agentcat the agentcat configuration
     */
    protected function streamingKernel(array $agentcat): TestKernel
    {
        return $this->kernel(agentcat: $agentcat, services: [ProgressTool::class => ProgressTool::class], mcp: TestKernel::STREAMING);
    }

    /**
     * The agentcat configuration with AgentCat on and a project. A test that forgets its collector fails closed: the API
     * URL is a closed local port.
     *
     * @return array<string, string>
     */
    protected function on(string $project): array
    {
        return ['enabled' => 'true', 'project_id' => $project, 'api_url' => MockCollector::closedPortUrl()];
    }

    /** `HttpHarness`'s API over the kernel's MCP route at `$path`: the `conformance` server's by default. */
    protected function http(TestKernel $kernel, string $path = SymfonyHttp::PATH): SymfonyHttp
    {
        return new SymfonyHttp($kernel, $path);
    }

    /**
     * The exception handler in place (PHP 8.3 has no `get_exception_handler()`).
     *
     * @return (callable(\Throwable): void)|null
     */
    private function exceptionHandler(): ?callable
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * The listeners of a kernel event, sorted: the last has the lowest priority. A `[service, method]` listener is given
     * as its service.
     *
     * @return list<mixed>
     */
    protected function listeners(TestKernel $kernel, string $event): array
    {
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $listeners = [];
        foreach ($dispatcher->getListeners($event) as $listener) {
            $listeners[] = is_array($listener) ? reset($listener) : $listener;
        }

        return $listeners;
    }

    /**
     * Flushes the global queue (1 s) and returns every delivery the API got.
     *
     * @return list<Delivery>
     */
    protected function deliveries(): array
    {
        EventQueue::global()->flush(1.0);

        return $this->transport->deliveries;
    }
}
