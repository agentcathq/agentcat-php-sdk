<?php

declare(strict_types=1);

namespace AgentCat\Internal;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\HttpContext;

/**
 * What tests, `AgentCat::fake()` and the framework bridges replace in a `Client`: its event queue, its enabled gate,
 * whether it sets up telemetry, and where the HTTP request of a call comes from. The constructor is private, so a
 * dependency-injection container cannot build one, and an autowired `Client` always gets the process-wide queue, the
 * default gate and the SAPI globals.
 *
 * @internal
 */
final readonly class ClientSeams
{
    /**
     * @param (\Closure(): bool)|null         $enabled
     * @param (\Closure(): ?HttpContext)|null $httpContext
     */
    private function __construct(
        public EventQueue $queue,
        public ?\Closure $enabled,
        public bool $telemetry,
        public ?\Closure $httpContext,
    ) {}

    /**
     * @param (\Closure(): bool)|null         $enabled     whether `track()` instruments; null keeps the default gate (D31, D44)
     * @param bool                            $telemetry   false sets up no exporters and no diagnostics (`AgentCat::fake()`)
     * @param (\Closure(): ?HttpContext)|null $httpContext the HTTP request of a call, from the framework (the Symfony
     *                                                     bundle's `RequestStack`), for every server the client tracks;
     *                                                     null reads the SAPI globals
     */
    public static function of(EventQueue $queue, ?\Closure $enabled = null, bool $telemetry = true, ?\Closure $httpContext = null): self
    {
        return new self($queue, $enabled, $telemetry, $httpContext);
    }
}
