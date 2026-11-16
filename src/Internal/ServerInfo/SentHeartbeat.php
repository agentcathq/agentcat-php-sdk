<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Delivery\Outstanding;
use AgentCat\Internal\Logging\Log;

/**
 * A heartbeat whose request is on its way: only whether its last answer is in. It holds nothing of the request that
 * started the heartbeat.
 *
 * @internal
 */
final class SentHeartbeat implements Outstanding
{
    private bool $settled = false;

    /** The last answer for the heartbeat is in, or it failed. */
    public function settle(): void
    {
        $this->settled = true;
    }

    #[\Override]
    public function settled(): bool
    {
        return $this->settled;
    }

    #[\Override]
    public function abandon(string $reason): void
    {
        Log::write('Skipped the server info heartbeat: ' . $reason);
    }
}
