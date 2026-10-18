<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Env;

/**
 * Where diagnostics go, read at each flush (TS `resolveEndpoint`, `resolveToken`). An empty variable counts as unset.
 * The names carry the `AGENTCAT_` prefix (D50, a divergence from TypeScript): an application's own `DIAGNOSTICS_ENDPOINT`
 * or `DIAGNOSTICS_TOKEN` is never read, so it never sends AgentCat's batches elsewhere or its secret to AgentCat.
 *
 * @internal
 */
final class DiagnosticsEndpoint
{
    /** `AGENTCAT_DIAGNOSTICS_ENDPOINT` or the default, trailing slashes removed, ending in `/v1/logs`. */
    public static function url(): string
    {
        $base = rtrim(Env::get('AGENTCAT_DIAGNOSTICS_ENDPOINT') ?? Constants::DEFAULT_DIAGNOSTICS_ENDPOINT, '/');

        return str_ends_with($base, '/v1/logs') ? $base : $base . '/v1/logs';
    }

    /** `AGENTCAT_DIAGNOSTICS_TOKEN` or the public default token. */
    public static function token(): string
    {
        return Env::get('AGENTCAT_DIAGNOSTICS_TOKEN') ?? Constants::DEFAULT_DIAGNOSTICS_TOKEN;
    }
}
