<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Support\Env;

/**
 * Where events go: `POST {base}/v1/public/events/`, and the server info: `POST {base}/v1/public/server-info/`.
 *
 * @internal
 */
final class ApiBaseUrl
{
    /** The trailing slash is required by the server. */
    public const string EVENTS_PATH = '/v1/public/events/';

    /** The trailing slash is required by the server. */
    public const string SERVER_INFO_PATH = '/v1/public/server-info/';

    /**
     * Option > `AGENTCAT_API_URL` (a framework bridge's configured value first, `BridgeConfig`) > `MCPCAT_API_URL` >
     * default; empty strings are skipped, trailing `/` trimmed.
     */
    public static function resolve(?string $option = null): string
    {
        foreach ([$option, BridgeConfig::env('AGENTCAT_API_URL'), Env::get('MCPCAT_API_URL')] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return rtrim($candidate, '/');
            }
        }

        return Constants::DEFAULT_API_BASE_URL;
    }

    public static function endpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::EVENTS_PATH;
    }

    /** Where the server info of a tracked server goes: `POST {base}/v1/public/server-info/`. */
    public static function serverInfoEndpoint(string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . self::SERVER_INFO_PATH;
    }
}
