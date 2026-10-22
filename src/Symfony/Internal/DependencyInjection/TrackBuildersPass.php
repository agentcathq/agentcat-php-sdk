<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal\DependencyInjection;

use AgentCat\Symfony\Internal\BuilderConfigurator;
use AgentCat\Symfony\Internal\ServerNames;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives each symfony/mcp-bundle server builder a configurator that tracks it. It reads the bundle's service definitions
 * only, by name: the services tagged `mcp.server.builder` with their `server` attribute, and the `addRequestHandlers`
 * method call with the tagged request handlers, its single argument. That call moves into the configurator, which
 * tracks first and adds the handlers after AgentCat's: mcp/sdk answers with the first handler that supports a request,
 * so a customer's `tools/call` handler becomes the handler AgentCat delegates to. A builder without that call, or with
 * a call of another shape, keeps its calls and is tracked after its handlers: the customer's handlers never go missing.
 * A server that `agentcat.exclude_servers` names (the names written in the configuration; the bundle reads those from
 * environment variables when it builds a server) keeps its definition. The pass writes every server name to
 * `agentcat.servers`, for those runtime names.
 *
 * It runs before the optimization passes at priority -100, after symfony/mcp-bundle's `McpAppPass` (10) and `McpPass`
 * (0), so the configurator sees every method call they add.
 *
 * @internal
 */
final class TrackBuildersPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $excluded = $container->hasParameter('agentcat.exclude_servers') ? $container->getParameter('agentcat.exclude_servers') : [];
        $excluded = is_array($excluded) ? $excluded : [];
        $builders = [];
        foreach ($container->findTaggedServiceIds('mcp.server.builder') as $id => $tags) {
            $attributes = $tags[0] ?? null;
            $server = is_array($attributes) ? ($attributes['server'] ?? null) : null;
            if (is_string($server)) {
                $builders[] = [$id, $server];
            }
        }
        $servers = array_column($builders, 1);
        $container->setParameter('agentcat.servers', $servers);
        foreach ($builders as [$id, $server]) {
            if (!ServerNames::excluded($server, $excluded, $servers)) {
                $this->track($container, $id, $server);
            }
        }
    }

    private function track(ContainerBuilder $container, string $id, string $server): void
    {
        $builder = $container->getDefinition($id);
        $handlers = [];
        $calls = [];
        foreach ($builder->getMethodCalls() as $call) {
            $method = is_array($call) ? ($call[0] ?? null) : null;
            $arguments = is_array($call) ? ($call[1] ?? null) : null;
            if (is_string($method) && strtolower($method) === 'addrequesthandlers' && is_array($arguments) && count($arguments) === 1 && ($arguments[0] ?? null) instanceof TaggedIteratorArgument) {
                $handlers[] = $arguments[0]; // added after AgentCat's own handlers
            } else {
                $calls[] = $call; // a call of another shape too: its handlers then come first
            }
        }
        $builder->setMethodCalls($calls);
        $configurator = 'agentcat.builder_configurator.' . $server;
        $container->register($configurator, BuilderConfigurator::class)
            ->setArguments([$server, new Reference('agentcat.server_tracker'), $handlers, $builder->getConfigurator()]);
        $builder->setConfigurator([new Reference($configurator), 'configure']);
    }
}
