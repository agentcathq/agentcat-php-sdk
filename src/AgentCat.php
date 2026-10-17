<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * The AgentCat entry point: `AgentCat::track($builder, 'proj_…')` before `$builder->build()`.
 *
 * Every method uses one shared `Client`. Applications with a dependency-injection container can inject a `Client`
 * instead, or give this class their own with `setClient()`.
 */
final class AgentCat
{
    private static ?Client $client = null;

    /**
     * Adds AgentCat analytics to an MCP server. Call it before the server is built: for mcp/sdk, pass the
     * `Mcp\Server\Builder` before `build()`.
     *
     * It never throws. When the server cannot be tracked, it logs why and returns the server unchanged. Tracking
     * the same server again changes nothing. In test environments (PHPUnit, Pest, `APP_ENV` `testing` or `test`)
     * it does nothing unless `AGENTCAT_ENABLED` is `true`.
     *
     * @template T of object
     *
     * @param T $server the server to track
     * @param string|null $projectId your AgentCat project ID (`proj_…`); null reads `AGENTCAT_PROJECT_ID`; `''`
     *                               sends events to no AgentCat project (telemetry only)
     * @param Options|null $options the tracking options; null uses the defaults
     *
     * @return T the same server
     */
    public static function track(object $server, ?string $projectId = null, ?Options $options = null): object
    {
        return self::client()->track($server, $projectId, $options);
    }

    /**
     * Sends the queued events now and waits for them. Rarely needed: AgentCat delivers on its own, between requests
     * and when the process ends.
     *
     * @param float|null $timeoutSeconds the longest wait in seconds; null waits until every event is sent
     *
     * @return bool true when every queued event was sent, false when the timeout ended first
     */
    public static function flush(?float $timeoutSeconds = null): bool
    {
        return self::client()->flush($timeoutSeconds);
    }

    /**
     * Stops accepting events, then sends the queued ones for at most 5 seconds. Events after this are dropped.
     * Rarely needed: AgentCat does this on its own when the process ends.
     */
    public static function shutdown(): void
    {
        self::client()->shutdown();
    }

    /** The shared client; the first call creates it. */
    public static function client(): Client
    {
        return self::$client ??= new Client();
    }

    /**
     * Replaces the shared client, for example with one from your dependency-injection container. Null goes back to
     * a new default client.
     */
    public static function setClient(?Client $client): void
    {
        self::$client = $client;
    }
}
