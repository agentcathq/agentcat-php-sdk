<?php

declare(strict_types=1);

namespace AgentCat\Internal;

use AgentCat\Internal\Delivery\EventQueue;

/**
 * What tests and `AgentCat::fake()` replace in a `Client`: its event queue and its enabled gate. The constructor is
 * private, so a dependency-injection container cannot build one, and an autowired `Client` always gets the
 * process-wide queue and the default gate.
 *
 * @internal
 */
final readonly class ClientSeams
{
    /** @param (\Closure(): bool)|null $enabled */
    private function __construct(
        public EventQueue $queue,
        public ?\Closure $enabled,
    ) {}

    /** @param (\Closure(): bool)|null $enabled whether `track()` instruments; null keeps the default gate (D31, D44) */
    public static function of(EventQueue $queue, ?\Closure $enabled = null): self
    {
        return new self($queue, $enabled);
    }
}
