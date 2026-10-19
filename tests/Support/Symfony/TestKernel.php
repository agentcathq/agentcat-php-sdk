<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\Symfony\AgentCatBundle;
use AgentCat\Tests\Support\MemoryLogger;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A Symfony application with symfony/mcp-bundle and one MCP server, `conformance` (`conformance-server` 2.0.0, HTTP on
 * `/mcp/conformance`), which exposes the tools under `Tools/`. Its cache and log directories live in the directory it
 * is given (`SymfonyTestCase` gives each kernel one under the test HOME). The `logger` service is a `MemoryLogger`, so
 * nothing reaches `error_log()`; a test that passes its own `logger` replaces it. Every `mcp.server.*` service is
 * public, so a test reads the servers and builders from the container.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** The `mcp.servers` entry of the `conformance` server. */
    public const array CONFORMANCE = ['name' => 'conformance-server', 'version' => '2.0.0', 'registry' => ['AgentCat\Tests\Support\Symfony\Tools\\'], 'http' => ['path' => '/mcp/conformance']];

    /** The default mcp configuration: the `conformance` server only. */
    public const array MCP = ['servers' => ['conformance' => self::CONFORMANCE]];

    /** Two servers: `conformance` and `second` (`second-server`, on `/mcp/second`), with the same tools. */
    public const array TWO_SERVERS = ['servers' => [
        'conformance' => self::CONFORMANCE,
        'second' => ['name' => 'second-server', 'http' => ['path' => '/mcp/second']] + self::CONFORMANCE,
    ]];

    /**
     * The `conformance` server with `Streaming\ProgressTool` too, whose answer is an SSE stream. The kernel needs the
     * tool as a service: `SymfonyTestCase::streamingKernel()` gives it both.
     */
    public const array STREAMING = ['servers' => ['conformance' => ['registry' => [...self::CONFORMANCE['registry'], 'AgentCat\Tests\Support\Symfony\Streaming\\']] + self::CONFORMANCE]];

    /**
     * @param string                       $directory the directory of the cache and log directories
     * @param array<string, mixed>         $agentcat  the agentcat configuration
     * @param array<string, string|object> $services  more services: id => class name or instance (synthetic)
     * @param array<string, mixed>         $mcp       the mcp configuration
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $agentcat = [],
        private readonly array $services = [],
        private readonly array $mcp = self::MCP,
        private readonly bool $withAgentCat = true,
        private readonly ?CompilerPassInterface $probe = null,
    ) {
        parent::__construct('test', false);
    }

    /** @return \Generator<int, Bundle> (Symfony 8.1 deprecates the interface that KernelInterface names here) */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new McpBundle();
        if ($this->withAgentCat) {
            yield new AgentCatBundle();
        }
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    #[\Override]
    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    #[\Override]
    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }

    /**
     * A container class of its own: kernels of one process with the same configuration would share the class that the
     * first one compiled, and that class reads paths relative to its own file (`%kernel.logs_dir%`, the lazy proxies),
     * so in the directory of the first kernel, which its test removed.
     */
    #[\Override]
    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . '_' . hash('xxh3', $this->directory);
    }

    /** Sets the synthetic services before the bundles boot, so a bundle's `boot()` can use them. */
    #[\Override]
    protected function initializeContainer(): void
    {
        parent::initializeContainer();
        foreach ($this->services as $id => $service) {
            if (is_object($service)) {
                $this->getContainer()->set($id, $service);
            }
        }
    }

    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'mcp.server.')) {
                        $definition->setPublic(true);
                    }
                }
            }
        });
        if ($this->probe instanceof CompilerPassInterface) {
            $container->addCompilerPass($this->probe, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false, 'router' => ['utf8' => true]]);
        $container->extension('mcp', $this->mcp);
        if ($this->withAgentCat) {
            $container->extension('agentcat', $this->agentcat);
        }
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('AgentCat\Tests\Support\Symfony\Tools\\', __DIR__ . '/Tools');
        $services->set('logger', MemoryLogger::class)->public();
        foreach ($this->services as $id => $service) {
            is_string($service) ? $services->set($id, $service)->public() : $services->set($id)->synthetic()->public();
        }
    }

    /** The MCP routes, from the bundle's route loader. */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'mcp');
    }
}
