<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

/** The end-to-end scenarios on the per-request lane in the handshake era: `initialize`, then calls in its session. */
final class PerRequestScenariosTest extends PerRequestScenarioTestCase
{
    #[\Override]
    protected static function modernEra(): bool
    {
        return false;
    }
}
