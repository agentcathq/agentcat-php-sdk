<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Env;

/**
 * Where diagnostics go, read at each flush (TS `resolveEndpoint`, `resolveToken`). An empty variable counts as unset.
 *
 * @internal
 */
final class DiagnosticsEndpoint
{
    /** `DIAGNOSTICS_ENDPOINT` or the default, trailing slashes removed, ending in `/v1/logs`. */
    public static function url(): string
    {
        $base = rtrim(Env::get('DIAGNOSTICS_ENDPOINT') ?? Constants::DEFAULT_DIAGNOSTICS_ENDPOINT, '/');

        return str_ends_with($base, '/v1/logs') ? $base : $base . '/v1/logs';
    }

    /** `DIAGNOSTICS_TOKEN` or the public default token. */
    public static function token(): string
    {
        return Env::get('DIAGNOSTICS_TOKEN') ?? Constants::DEFAULT_DIAGNOSTICS_TOKEN;
    }
}
