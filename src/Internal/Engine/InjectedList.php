<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * An advertised tool list and what was injected into it.
 *
 * @internal
 */
final readonly class InjectedList
{
    /**
     * @param list<mixed> $tools the tools to advertise
     * @param array<array-key, list<string>> $registry injected parameters per tool name (PHP turns numeric-string names into int keys)
     * @param list<string> $outputRegistry tools whose outputSchema declares `mcp_session`
     * @param bool $degraded the injection failed as a whole and `$tools` is the original list
     */
    public function __construct(
        public array $tools,
        public array $registry,
        public array $outputRegistry,
        public bool $degraded = false,
    ) {}
}
