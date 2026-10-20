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
use AgentCat\Tests\Support\WarmUp;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Symfony applications with symfony/mcp-bundle, for the bundle tests. Skipped where the bundle is not installed (the
 * mcp/sdk main leg removes it), unless a test class needs no symfony/mcp-bundle (`needsTheMcpBundle()`). Each kernel compiles its container into a directory of this test under the test HOME,
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

    /** @var list<KernelInterface> the kernels this test booted */
    private array $kernels = [];

    private ?Environment $environment = null;

    /** Whether this process ran its warm-up kernel (`setUpBeforeClass()`). */
    private static bool $warm = false;

    /**
     * The first container that a PHP process compiles is slow: PHP compiles the files of Symfony, symfony/mcp-bundle and
     * mcp/sdk then (about 0.2 s more on PHP 8.5, up to 1.5 s on a loaded host). So, once per process and before the
     * first test class runs, a test case of its own boots one kernel with AgentCat on, sends it a tool call and tears it
     * down, outside the time the slow-test detector measures for a test. `WarmUp::run()` makes a
     * notice, warning or deprecation from a file of `src/` fail the class, as it would fail the test that included it.
     */
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!self::$warm && class_exists(McpBundle::class)) {
            self::$warm = true;
            self::warmUp();
        }
    }

    /** Runs one test case's whole lifecycle, as PHPUnit does: a kernel, its handshake, a listing and a tool call. */
    private static function warmUp(): void
    {
        $warmUp = new class ('testWarmUp') extends SymfonyTestCase {
            public function testWarmUp(): void
            {
                $http = $this->http($this->kernel(agentcat: $this->on('proj_wwwwwwwwwwwwwwwwwwwwwwwwwww')), terminate: true);
                $http->listTools();
                $http->callTool('echo', ['msg' => 'warm-up', 'session_id' => 'start']);
            }
        };
        WarmUp::run(static function () use ($warmUp): void {
            $warmUp::setUpBeforeClass();
            $warmUp->setUp();
            try {
                $warmUp->testWarmUp();
            } finally {
                $warmUp->tearDown();
                $warmUp::tearDownAfterClass();
            }
        });
    }

    #[\Override]
    protected function setUp(): void
    {
        if ($this->needsTheMcpBundle() && !class_exists(McpBundle::class)) {
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

    /** Whether the tests of the class need symfony/mcp-bundle: without it, they skip. */
    protected function needsTheMcpBundle(): bool
    {
        return true;
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
     * @param array<string, mixed>         $agentcat  the agentcat configuration
     * @param array<string, string|object> $services  more services: id => class name or instance (synthetic)
     * @param array<string, mixed>|null    $mcp       the mcp configuration; null: the `conformance` server only
     * @param string|null                  $directory the kernel's directory; null: a new one of this test. A kernel
     *                                                loads the container that an earlier kernel of its directory
     *                                                compiled, as each request of PHP-FPM does. A test that passes a
     *                                                directory gives each configuration its own and removes them
     * @param CompilerPassInterface|null   $before    a pass that runs before AgentCat's (`TestKernel`)
     */
    protected function kernel(array $agentcat = [], array $services = [], bool $withAgentCat = true, ?CompilerPassInterface $probe = null, ?array $mcp = null, ?string $directory = null, ?CompilerPassInterface $before = null): TestKernel
    {
        $kernel = new TestKernel($directory ?? $this->directory() . '/kernel-' . count($this->kernels), $agentcat, $services, $mcp ?? TestKernel::MCP, $withAgentCat, $probe, $before);
        $this->boot($kernel);

        return $kernel;
    }

    /** Boots a kernel of this test, which shuts it down after the test. */
    protected function boot(KernelInterface $kernel): void
    {
        $this->kernels[] = $kernel;
        $handler = $this->exceptionHandler();
        $kernel->boot();
        if ($this->exceptionHandler() !== $handler) {
            restore_exception_handler(); // FrameworkBundle::boot() leaves Symfony's handler with symfony/error-handler 7.3
        }
    }

    /** This test's directory under the test HOME, removed after the test. */
    protected function directory(): string
    {
        return $this->directory;
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

    /**
     * `HttpHarness`'s API over the kernel's MCP route at `$path`: the `conformance` server's by default. With `$terminate`,
     * each request ends with the kernel's terminate phase, as with a customer's `KernelBrowser`.
     */
    protected function http(TestKernel $kernel, string $path = SymfonyHttp::PATH, bool $terminate = false): SymfonyHttp
    {
        return new SymfonyHttp($kernel, $path, $terminate);
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
    protected function listeners(KernelInterface $kernel, string $event): array
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
