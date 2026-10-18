<?php

declare(strict_types=1);

namespace AgentCat;

use AgentCat\Internal\Logging\Log;
use AgentCat\Testing\AgentCatFake;
use AgentCat\Testing\Internal\FakeLog;

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
     * Publishes a custom event, for a tracked server or for a session.
     *
     * With a server tracked by any client (the Builder, or the server built from it), the event goes to the project
     * the server was tracked with, whatever `$projectId` says, and carries the server name and version and the SDK
     * fields. The server's `redactEvent` option applies to it; `redactSensitiveInformation` does not. When the server
     * was tracked with `enableTracing: false`, the event is dropped. Without `$event->sessionId`, the event has no
     * session.
     *
     * With a session ID string, the event goes to `$projectId` for that session, used as it is; `$event->sessionId`
     * wins over it. No server or SDK fields and no hooks apply.
     *
     * The event is queued, not sent: AgentCat delivers it like any other event. Apart from the two errors below, it
     * never throws: a failure is logged. When a `track()` in this process could not track a server, or ran while
     * AgentCat was off, a server that is not tracked logs a warning instead of the second error. In test environments
     * it validates `$projectId` and then does nothing, like `track()` (see `AGENTCAT_ENABLED` there).
     *
     * In a file without `declare(strict_types=1)`, PHP turns an int, float or bool first argument into a session ID
     * string.
     *
     * @param object|string $serverOrSessionId A tracked server, or the session ID of the event.
     * @param string|null $projectId Your AgentCat project ID (`proj_…`); required, even for a tracked server.
     * @param CustomEvent|null $event The event data; null sends an event with no data.
     *
     * @throws \InvalidArgumentException when `$projectId` is null or empty, or when the server is not tracked
     */
    public static function publishCustomEvent(object|string $serverOrSessionId, ?string $projectId, ?CustomEvent $event = null): void
    {
        self::client()->publishCustomEvent($serverOrSessionId, $projectId, $event);
    }

    /**
     * Sends the queued events now and waits for them. Rarely needed: AgentCat delivers on its own, between requests
     * and when the process ends.
     *
     * @param float|null $timeoutSeconds The longest wait in seconds; null waits until nothing is left to send.
     *
     * @return bool True when nothing is left to send (every event delivered, or given up after its retries, and no
     *              exporter request left); false when the timeout ended first. AgentCat's own diagnostics are neither
     *              counted nor waited for: they go out on their own, between requests and when the process ends.
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

    /**
     * Starts recording for your test suite: installs a client that records every event and sends nothing, and
     * returns the fake to assert on. Restore the real client with `AgentCat::setClient(null)` in `tearDown()`.
     *
     * Call it first, before the application builds its servers. In a test environment, a server tracked before
     * `fake()` is not tracked at all: no tool call of it is recorded. With `AGENTCAT_ENABLED=true`, a server tracked
     * before `fake()` keeps the client that tracked it.
     *
     * While the fake is the shared client, AgentCat logs to the fake's `logs()` only, and adopts no Builder's PSR-3
     * logger. `setClient()` brings back the log routing from before the first `fake()`.
     *
     * The fake records whatever `AGENTCAT_ENABLED` says, so a CI that sets `AGENTCAT_ENABLED=false` still works. Test
     * environments without `fake()` stay silent: `track()` does nothing there.
     */
    public static function fake(): AgentCatFake
    {
        $fake = new AgentCatFake();
        self::setClient($fake->client());

        return $fake;
    }

    /** The shared client; the first call creates it. */
    public static function client(): Client
    {
        return self::$client ??= new Client();
    }

    /**
     * Replaces the shared client, for example with one from your dependency-injection container. Null goes back to
     * a new default client. Replacing the client of a fake brings back the log routing from before the first
     * `fake()`.
     */
    public static function setClient(?Client $client): void
    {
        self::$client = $client;
        Log::endDiversion();
        $fakeLog = $client instanceof Client ? FakeLog::of($client) : null;
        if ($fakeLog instanceof FakeLog) {
            Log::divert($fakeLog); // a fake's client: AgentCat logs to the fake only
        }
    }
}
