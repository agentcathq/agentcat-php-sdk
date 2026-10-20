<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * The configurator of one symfony/mcp-bundle server builder (`TrackBuildersPass`). The container calls it after every
 * method call of the builder's definition, so AgentCat tracks a Builder that has its logger, its reference handler and
 * its container, when the container builds it: an MCP request, `mcp:server`, the profiler.
 * Booting the kernel tracks nothing.
 *
 * @internal
 */
final readonly class BuilderConfigurator
{
    /**
     * @param list<mixed> $requestHandlers the first argument of each `addRequestHandlers` call the pass removed: the
     *                                     tagged request handlers
     * @param mixed       $previous        the configurator the builder had, a callable once the container resolves it
     */
    public function __construct(
        private string $server,
        private ServerTracker $tracker,
        private array $requestHandlers,
        private mixed $previous = null,
    ) {}

    public function configure(Builder $builder): void
    {
        if (is_callable($this->previous)) {
            ($this->previous)($builder);
        }
        $this->tracker->track($builder, $this->server); // never throws: the customer's handlers are added in any case
        foreach ($this->requestHandlers as $handlers) {
            if (is_iterable($handlers)) {
                /** @var iterable<RequestHandlerInterface<mixed>> $handlers the services tagged mcp.request_handler */
                $builder->addRequestHandlers($handlers);
            }
        }
    }
}
