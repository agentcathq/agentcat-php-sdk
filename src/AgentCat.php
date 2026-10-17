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

    /** @codeCoverageIgnore A static class: nothing creates an instance, so no code runs here. */
    private function __construct() {}

    /**
     * Adds AgentCat analytics to an MCP server. Call it before the server is built: for mcp/sdk, pass the
     * `Mcp\Server\Builder` before `build()`.
     *
     * It never throws. When the server cannot be tracked, it logs why and returns the server unchanged. Tracking
     * the same server again, from any client, changes nothing.
     *
     * `AGENTCAT_ENABLED` turns AgentCat off everywhere with `false`, `0`, `no` or `off`, and on everywhere with
     * `true`, `1`, `yes` or `on`. Otherwise AgentCat does nothing in test environments (PHPUnit, Pest, `APP_ENV`
     * `testing` or `test`).
     *
     * @template T of object
     *
     * @param T $server The server to track.
     * @param string|null $projectId Your AgentCat project ID (`proj_…`). Null reads `AGENTCAT_PROJECT_ID`; `''` sends
     *                               the events to no AgentCat project (telemetry only).
     * @param Options|null $options The tracking options; null uses the defaults.
     *
     * @return T The same server.
     */
    public static function track(object $server, ?string $projectId = null, ?Options $options = null): object
    {
        return self::client()->track($server, $projectId, $options);
    }

    /**
     * Sends the queued events now and waits for them. Rarely needed: AgentCat delivers on its own, between requests
     * and when the process ends.
     *
     * @param float|null $timeoutSeconds The longest wait in seconds; null waits until nothing is left to send.
     *
     * @return bool True when nothing is left to send (every event delivered, or given up after its retries); false
     *              when the timeout ended first.
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
