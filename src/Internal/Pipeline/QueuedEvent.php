<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Event;

/**
 * An event waiting for the pipeline, with the hooks that apply to it.
 *
 * @internal
 */
final class QueuedEvent
{
    /**
     * @param (\Closure(Event): (Event|null))|null $redactEvent
     * @param (\Closure(string): string)|null      $redactSensitiveInformation
     * @param list<\Closure(Event): void>          $pending                    deferred hook results
     */
    public function __construct(
        public readonly Event $event,
        public ?\Closure $redactEvent = null,
        public ?\Closure $redactSensitiveInformation = null,
        public array $pending = [],
    ) {}
}
