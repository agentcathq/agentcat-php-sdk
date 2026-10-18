<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

use AgentCat\Internal\Logging\Log;

/**
 * The enabled gate of a framework bridge, from its `agentcat.enabled` configuration (plan E1 header conflict 12): the
 * configuration decides, because a cached configuration hides the `.env` file from `Env::get()`.
 *
 * @internal
 */
final class EnabledGate
{
    /**
     * The D44 words decide (`TestMode::word()`). Unset or blank: `TestMode::sdkEnabled()`, which reads
     * `AGENTCAT_ENABLED` of the process and is off in test environments (D31, D44). Any other value logs one line and
     * the D31 default is used: off in test environments, on elsewhere. It does not read `AGENTCAT_ENABLED` again, which
     * holds the same value when `env()` filled the configuration, and would log a second line for it.
     *
     * @return \Closure(): bool
     */
    public static function of(mixed $configured): \Closure
    {
        $word = TestMode::word($configured);
        if ($word !== null) {
            return static fn(): bool => $word;
        }
        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return TestMode::sdkEnabled(...);
        }
        Log::write(sprintf('WARN: AgentCat config "agentcat.enabled" is "%s", not one of true, 1, yes, on, false, 0, no, off; the default is used', is_scalar($configured) ? (string) $configured : get_debug_type($configured)));

        return static fn(): bool => !TestMode::inTestEnvironment();
    }
}
