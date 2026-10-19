<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Symfony;

use AgentCat\Symfony\Internal\BuilderConfigurator;
use AgentCat\Symfony\Internal\DependencyInjection\TrackBuildersPass;
use AgentCat\Symfony\Internal\ServerTracker;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** The pass on a bare `ContainerBuilder` with builder definitions as symfony/mcp-bundle writes them (header conflict 3). */
final class TrackBuildersPassTest extends TestCase
{
    /** Header conflict 1: AgentCat's handlers go first, the customer's tagged ones after them, in the configurator. */
    public function testTheTaggedHandlersMoveIntoAConfiguratorThatRunsAfterTheOtherCalls(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('agentcat.exclude_servers', []);
        $container->register('agentcat.server_tracker', ServerTracker::class);
        $handlers = new TaggedIteratorArgument('mcp.request_handler');
        $container->register('mcp.server.main.builder', Builder::class)
            ->setFactory([Server::class, 'builder'])
            ->addMethodCall('setEventDispatcher', [new Reference('event_dispatcher')])
            ->addMethodCall('addRequestHandlers', [$handlers])
            ->addMethodCall('setLogger', [new Reference('logger')])
            ->addTag('mcp.server.builder', ['server' => 'main']);

        (new TrackBuildersPass())->process($container);

        $builder = $container->getDefinition('mcp.server.main.builder');
        $this->assertSame(['setEventDispatcher', 'setLogger'], array_column($builder->getMethodCalls(), 0));
        $this->assertEquals([new Reference('agentcat.builder_configurator.main'), 'configure'], $builder->getConfigurator());
        $configurator = $container->getDefinition('agentcat.builder_configurator.main');
        $this->assertSame(BuilderConfigurator::class, $configurator->getClass());
        $this->assertEquals(['main', new Reference('agentcat.server_tracker'), [$handlers], null], $configurator->getArguments());
    }

    public function testAnExcludedServerIsLeftAsItIs(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('agentcat.exclude_servers', ['internal']);
        $container->register('mcp.server.internal.builder', Builder::class)->addMethodCall('addRequestHandlers', [new TaggedIteratorArgument('mcp.request_handler')])->addTag('mcp.server.builder', ['server' => 'internal']);

        (new TrackBuildersPass())->process($container);

        $this->assertNull($container->getDefinition('mcp.server.internal.builder')->getConfigurator());
        $this->assertCount(1, $container->getDefinition('mcp.server.internal.builder')->getMethodCalls());
        $this->assertFalse($container->hasDefinition('agentcat.builder_configurator.internal'));
    }

    public function testAConfiguratorTheBuilderHadRunsFirst(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('agentcat.exclude_servers', []);
        $container->register('mcp.server.main.builder', Builder::class)->setConfigurator([new Reference('app.configurator'), 'configure'])->addTag('mcp.server.builder', ['server' => 'main']);

        (new TrackBuildersPass())->process($container);

        $this->assertEquals([new Reference('app.configurator'), 'configure'], $container->getDefinition('agentcat.builder_configurator.main')->getArgument(3));
    }

    /** Header conflict 3: without the call, the builder is still tracked, after its handlers (the C1 behavior). */
    public function testABuilderWithoutTheHandlersCallIsStillTracked(): void
    {
        $container = new ContainerBuilder();
        $container->register('mcp.server.main.builder', Builder::class)->addMethodCall('setLogger', [new Reference('logger')])->addTag('mcp.server.builder', ['server' => 'main']);

        (new TrackBuildersPass())->process($container); // no agentcat.exclude_servers parameter either

        $this->assertCount(1, $container->getDefinition('mcp.server.main.builder')->getMethodCalls());
        $this->assertEquals(['main', new Reference('agentcat.server_tracker'), [], null], $container->getDefinition('agentcat.builder_configurator.main')->getArguments());
    }

    /** Every builder gets its own configurator; the case of the method name does not matter to PHP, nor here. */
    public function testEachServerGetsItsOwnConfigurator(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('agentcat.exclude_servers', []);
        foreach (['main', 'internal'] as $server) {
            $container->register('mcp.server.' . $server . '.builder', Builder::class)->addMethodCall('AddRequestHandlers', [[new Reference('app.' . $server)]])->addTag('mcp.server.builder', ['server' => $server]);
        }

        (new TrackBuildersPass())->process($container);

        foreach (['main', 'internal'] as $server) {
            $this->assertSame([], $container->getDefinition('mcp.server.' . $server . '.builder')->getMethodCalls());
            $this->assertEquals([$server, new Reference('agentcat.server_tracker'), [[new Reference('app.' . $server)]], null], $container->getDefinition('agentcat.builder_configurator.' . $server)->getArguments());
        }
    }

    public function testAServiceWithoutAServerNameIsLeftAsItIs(): void
    {
        $container = new ContainerBuilder();
        $container->register('mcp.server.main.builder', Builder::class)->addMethodCall('addRequestHandlers', [[]])->addTag('mcp.server.builder');

        (new TrackBuildersPass())->process($container);

        $this->assertNull($container->getDefinition('mcp.server.main.builder')->getConfigurator());
        $this->assertCount(1, $container->getDefinition('mcp.server.main.builder')->getMethodCalls());
    }

    /**
     * symfony/config writes the `mcp.servers` key `my-server` as `my_server`, so `exclude_servers: [my-server]` names
     * it. A name that is a server itself stays exact.
     */
    public function testAnExclusionNamesTheServerAsSymfonyWritesItsKey(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('agentcat.exclude_servers', ['my-server', 'a-b', 'c-d_e']);
        foreach (['my_server', 'a-b', 'a_b', 'c_d_e'] as $server) {
            $container->register('mcp.server.' . $server . '.builder', Builder::class)->addTag('mcp.server.builder', ['server' => $server]);
        }

        (new TrackBuildersPass())->process($container);

        $this->assertNull($container->getDefinition('mcp.server.my_server.builder')->getConfigurator());
        $this->assertNull($container->getDefinition('mcp.server.a-b.builder')->getConfigurator());
        $this->assertNotNull($container->getDefinition('mcp.server.a_b.builder')->getConfigurator());
        $this->assertNotNull($container->getDefinition('mcp.server.c_d_e.builder')->getConfigurator()); // c-d_e keeps its hyphen in Symfony
    }
}
