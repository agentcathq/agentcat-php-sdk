<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * The tracking options that shape a tool listing.
 *
 * @internal
 */
final readonly class InjectionConfig
{
    public function __construct(
        public bool $enableReportMissing = true,
        public bool $enableTracing = true,
        public bool $enableToolCallContext = true,
        public ?string $customContextDescription = null,
        public bool $enableAgentTracking = false,
        public bool $hookMode = false,
    ) {}

    /** Prompted mode: tracing on and no session hook. */
    public function injectSessionId(): bool
    {
        return $this->enableTracing && !$this->hookMode;
    }

    public function injectAgentId(): bool
    {
        return $this->enableTracing && $this->enableAgentTracking;
    }
}
