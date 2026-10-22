<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A compiler pass that writes the `addRequestHandlers` call of each symfony/mcp-bundle server builder in another shape,
 * as a later release of the bundle can write it: the call gets the arguments that `$arguments` makes of the bundle's
 * own argument, the tagged request handlers. `TestKernel` runs it before AgentCat's pass.
 */
final readonly class HandlersCallShape implements CompilerPassInterface
{
    /** @param \Closure(TaggedIteratorArgument): array<array-key, mixed> $arguments */
    public function __construct(private \Closure $arguments) {}

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->findTaggedServiceIds('mcp.server.builder')) as $id) {
            $definition = $container->getDefinition($id);
            $calls = [];
            foreach ($definition->getMethodCalls() as $call) {
                $arguments = is_array($call) ? ($call[1] ?? null) : null;
                $handlers = is_array($arguments) ? ($arguments[0] ?? null) : null;
                $bundlesCall = is_array($call) && ($call[0] ?? null) === 'addRequestHandlers' && $handlers instanceof TaggedIteratorArgument;
                $calls[] = $bundlesCall ? ['addRequestHandlers', ($this->arguments)($handlers)] : $call;
            }
            $definition->setMethodCalls($calls);
        }
    }
}
