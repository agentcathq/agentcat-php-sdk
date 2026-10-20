<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\AgentCat;
use AgentCat\CustomEvent;
use AgentCat\Symfony\AgentCatBundle;
use AgentCat\Tests\Support\MemoryLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A Symfony application with FrameworkBundle and AgentCatBundle only, and no symfony/mcp-bundle: the Flex recipe
 * registers AgentCatBundle in every application that requires agentcat/sdk, a plain mcp/sdk one too. Its one route,
 * `/hello`, publishes a custom event and answers `hello`. Its cache and log directories live in the directory it is
 * given. The `logger` service is a `MemoryLogger`, so nothing reaches `error_log()`. It names no class of
 * symfony/mcp-bundle, so it runs where the bundle is not installed.
 */
final class WithoutMcpBundleKernel extends Kernel
{
    use MicroKernelTrait;

    public const string PROJECT = 'proj_hhhhhhhhhhhhhhhhhhhhhhhhhhh';

    public const string SESSION = 'ses_hello';

    /**
     * @param string               $directory the directory of the cache and log directories
     * @param array<string, mixed> $agentcat  the agentcat configuration
     */
    public function __construct(private readonly string $directory, private readonly array $agentcat = [])
    {
        parent::__construct('test', false);
    }

    /** @return \Generator<int, Bundle> (Symfony 8.1 deprecates the interface that KernelInterface names here) */
    #[\Override]
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AgentCatBundle();
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

    /** The controller of `/hello`: it publishes a custom event for the session `ses_hello`, as an application's code does. */
    public function hello(): Response
    {
        AgentCat::publishCustomEvent(self::SESSION, self::PROJECT, new CustomEvent(message: 'hello'));

        return new Response('hello');
    }

    /** A container class of its own, as `TestKernel`'s: the class reads paths relative to its own file. */
    #[\Override]
    protected function getContainerClass(): string
    {
        return parent::getContainerClass() . '_' . hash('xxh3', $this->directory);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false, 'router' => ['utf8' => true]]);
        $container->extension('agentcat', $this->agentcat);
        $container->services()->set('logger', MemoryLogger::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('hello', '/hello')->controller('kernel::hello'); // MicroKernelTrait's service of the kernel
    }
}
