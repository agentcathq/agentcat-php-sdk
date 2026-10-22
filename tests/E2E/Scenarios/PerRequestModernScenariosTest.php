<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Scenarios;

/** The end-to-end scenarios on the per-request lane in the 2026-07-28 era: no handshake, the envelope on every request. */
final class PerRequestModernScenariosTest extends PerRequestScenarioTestCase
{
    #[\Override]
    protected static function modernEra(): bool
    {
        return true;
    }
}
