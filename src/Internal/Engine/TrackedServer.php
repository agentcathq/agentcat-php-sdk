<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Options;

/**
 * The engine state of one tracked server: its options, listing state, injection registries and the publisher of
 * the client that tracked it.
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
     * @param Publisher $publisher the publisher of the client that tracked the server
     * @param (\Closure(): ?HttpContext)|null $httpContext the HTTP request of a call, from the framework; null reads the
     *                                                    SAPI globals. The laravel/mcp adapter reads the server's own
     *                                                    transport instead (plan E1 header conflict 9)
     */
    public function __construct(
        public readonly string $projectId,
        public readonly Options $options,
        private readonly \Closure $serverInfo,
        private readonly Publisher $publisher,
        public readonly ?\Closure $httpContext = null,
    ) {
        $this->injection = new InjectionState();
    }

    /** The publisher of the client that tracked the server: its events, custom ones included, go out through it. */
    public function publisher(): Publisher
    {
        return $this->publisher;
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
