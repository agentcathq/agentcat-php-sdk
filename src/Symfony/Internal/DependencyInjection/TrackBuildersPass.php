<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal\DependencyInjection;

use AgentCat\Symfony\Internal\BuilderConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives each symfony/mcp-bundle server builder a configurator that tracks it (plan E2 header conflicts 1-3, 14). It
 * reads the bundle's service definitions only, by name: the services tagged `mcp.server.builder` with their `server`
 * attribute, and the `addRequestHandlers` method call with the tagged request handlers. That call moves into the
 * configurator, which tracks first and adds the handlers after AgentCat's: mcp/sdk answers with the first handler
 * that supports a request, so a customer's `tools/call` handler becomes the handler AgentCat delegates to. A builder
 * without the call is tracked after its handlers. A server named in `agentcat.exclude_servers` keeps its definition.
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
        foreach ($builders as [$id, $server]) {
            if (!$this->excluded($server, $excluded, $servers)) {
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
            if (is_string($method) && strtolower($method) === 'addrequesthandlers') {
                $arguments = $call[1] ?? null;
                $handlers[] = is_array($arguments) ? ($arguments[0] ?? []) : []; // added after AgentCat's own handlers (header conflict 1)
            } else {
                $calls[] = $call;
            }
        }
        $builder->setMethodCalls($calls);
        $configurator = 'agentcat.builder_configurator.' . $server;
        $container->register($configurator, BuilderConfigurator::class)
            ->setArguments([$server, new Reference('agentcat.server_tracker'), $handlers, $builder->getConfigurator()]);
        $builder->setConfigurator([new Reference($configurator), 'configure']);
    }

    /**
     * Whether `exclude_servers` names the server. symfony/config writes a key `my-server` of `mcp.servers` as
     * `my_server` (a key that holds an underscore, or whose other spelling is a key too, stays as written), so an entry
     * names a server by either spelling.
     *
     * @param array<mixed> $excluded
     * @param list<string> $servers  every server name
     */
    private function excluded(string $server, array $excluded, array $servers): bool
    {
        foreach ($excluded as $entry) {
            if (is_string($entry) && $server === (in_array($entry, $servers, true) || str_contains($entry, '_') ? $entry : str_replace('-', '_', $entry))) {
                return true;
            }
        }

        return false;
    }
}
