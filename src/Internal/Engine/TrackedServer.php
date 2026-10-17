<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Options;

/**
 * The engine state of one tracked server: its options, listing state and injection registries.
 *
 * @internal
 */
final class TrackedServer
{
    public readonly InjectionState $injection;

    /** @var array<array-key, list<string>>|null injected parameters per tool; null until a listing succeeded */
    public ?array $registry = null;

    /** @var list<string>|null tools whose outputSchema declares `mcp_session` */
    public ?array $outputRegistry = null;

    /**
     * @param string $projectId '' in telemetry-only mode
     * @param \Closure(): array{0: ?string, 1: ?string} $serverInfo server name and version, read when an event is published
     */
    public function __construct(
        public readonly string $projectId,
        public readonly Options $options,
        private readonly \Closure $serverInfo,
    ) {
        $this->injection = new InjectionState();
    }

    public function hookMode(): bool
    {
        return $this->options->resolveSessionId instanceof \Closure;
    }

    public function injectionConfig(): InjectionConfig
    {
        return new InjectionConfig(
            $this->options->enableReportMissing,
            $this->options->enableTracing,
            $this->options->enableToolCallContext,
            $this->options->customContextDescription,
            $this->options->enableAgentTracking,
            $this->hookMode(),
        );
    }

    /** Mirror the minted session into a result only when the tool declares `mcp_session` (02 §2.8). */
    public function mirrorAllowed(string $toolName): bool
    {
        return $this->outputRegistry === null || in_array($toolName, $this->outputRegistry, true);
    }

    /** Replaces both registries wholesale. */
    public function adopt(InjectedList $list): void
    {
        $this->registry = $list->registry;
        $this->outputRegistry = $list->outputRegistry;
    }

    /** @return array{0: ?string, 1: ?string} */
    public function serverInfo(): array
    {
        return ($this->serverInfo)();
    }
}
