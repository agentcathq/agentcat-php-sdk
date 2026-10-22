<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A compiler pass that records each symfony/mcp-bundle server builder as the container compiles: the service ID, the
 * factory, the method calls and the configurator of every service tagged `mcp.server.builder`, under the tag's
 * `server` attribute. `TestKernel` runs it where AgentCat's own pass runs, so a test sees what that pass gets.
 */
final class DefinitionsProbe implements CompilerPassInterface
{
    /**
     * @var array<string, array{id: string, factory: string|array<mixed>|null, calls: list<array{string, array<mixed>}>, configurator: string|array<mixed>|null}>
     *            per server name
     */
    public array $builders = [];

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('mcp.server.builder') as $id => $tags) {
            $definition = $container->getDefinition($id);
            $calls = [];
            foreach ($definition->getMethodCalls() as $call) {
                if (is_array($call) && is_string($call[0] ?? null) && is_array($call[1] ?? null)) {
                    $calls[] = [$call[0], $call[1]];
                }
            }
            foreach ($tags as $attributes) {
                $server = is_array($attributes) ? ($attributes['server'] ?? null) : null;
                if (is_string($server)) {
                    $this->builders[$server] = ['id' => $id, 'factory' => $definition->getFactory(), 'calls' => $calls, 'configurator' => $definition->getConfigurator()];
                }
            }
        }
    }
}
