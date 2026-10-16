<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * Listing state of one tracked server; lives as long as its tracking config.
 *
 * @internal
 */
final class InjectionState
{
    /** @var array<array-key, true> */
    private array $declaredSessionParams = [];

    /** @var array<array-key, true> */
    private array $reportedConflicts = [];

    private bool $shadowWarned = false;

    /**
     * Tools whose own schema declares `session_id`. Only grows. PHP turns numeric-string names into int keys.
     *
     * @return array<array-key, true>
     */
    public function declaredSessionParams(): array
    {
        return $this->declaredSessionParams;
    }

    public function markDeclaredSessionParam(string $tool): void
    {
        $this->declaredSessionParams[$tool] = true;
    }

    public function hasDeclaredSessionParam(string $tool): bool
    {
        return isset($this->declaredSessionParams[$tool]);
    }

    /** True the first time a tool's session_id conflict is reported. */
    public function reportConflictOnce(string $tool): bool
    {
        if ($this->reportedConflicts[$tool] ?? false) {
            return false;
        }
        $this->reportedConflicts[$tool] = true;

        return true;
    }

    /** True the first time the get_more_tools shadowing is warned about. */
    public function warnShadowOnce(): bool
    {
        if ($this->shadowWarned) {
            return false;
        }
        $this->shadowWarned = true;

        return true;
    }
}
