<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\Clock;
use AgentCat\Internal\Tools\GetMoreTools;

/**
 * The server info heartbeat: about once an hour, AgentCat sends what an agent sees on a tracked server (its name,
 * version, title and instructions, and its lists of tools, resources, resource templates and prompts) to
 * `POST /v1/public/server-info/`.
 *
 * The adapters call a check when a `tools/list` or `tools/call` request has been answered. A check reads memory only
 * (`Guard`) and never throws. When a heartbeat is due, it keeps the request's context in a `PendingHeartbeat` and
 * hands that to the event queue of the server, which runs it after the events, where the client already has its
 * response (`EventQueue::defer()`). No timer exists: a server without requests sends nothing.
 *
 * A heartbeat is due for a tracked server with a project and with tracing on, when no attempt for its project and
 * server name started in the last hour of this process's memory. A tool list request and a `get_more_tools` call start
 * a due heartbeat in any memory; another tool call does so only once the memory is 60 seconds old, so a runtime that
 * keeps no memory between requests (PHP-FPM) sends one for each tool list request and feedback note, not for each call.
 *
 * @internal
 */
final class Heartbeat
{
    /** @var (\Closure(TrackedServer, bool): void)|null */
    private static ?\Closure $replacement = null;

    /** @var (\Closure(): float)|null */
    private static ?\Closure $clock = null;

    /**
     * The check of an answered `tools/list` request.
     *
     * @param \Closure(): ServerInfoSource $source the server's lists with the context of this request; called only when
     *                                             a heartbeat is due, and it must only keep that context, not use it
     */
    public static function onToolList(TrackedServer $server, \Closure $source): void
    {
        self::check($server, true, $source);
    }

    /**
     * The check of an answered `tools/call` request. A call of AgentCat's `get_more_tools` is a feedback note.
     *
     * @param \Closure(): ServerInfoSource $source as for `onToolList()`
     */
    public static function onToolCall(TrackedServer $server, string $toolName, \Closure $source): void
    {
        self::check($server, $server->options->enableReportMissing && $toolName === GetMoreTools::NAME, $source);
    }

    /**
     * Tests only: every check goes to `$replacement`, with the server and whether the request starts a heartbeat in any
     * memory, and nothing else happens; null puts the heartbeat back.
     *
     * @param (\Closure(TrackedServer, bool): void)|null $replacement
     */
    public static function replace(?\Closure $replacement): void
    {
        self::$replacement = $replacement;
    }

    /** Tests only: whether `replace()` put something in the place of the heartbeat. */
    public static function replaced(): bool
    {
        return self::$replacement instanceof \Closure;
    }

    /**
     * Tests only: the clock of the guard and of the build limits; null puts the monotonic clock back.
     *
     * @param (\Closure(): float)|null $clock
     */
    public static function setClock(?\Closure $clock): void
    {
        self::$clock = $clock;
    }

    /** Tests only: an empty memory, as a new process has. */
    public static function forget(): void
    {
        Guard::forget();
    }

    /** @param \Closure(): ServerInfoSource $source */
    private static function check(TrackedServer $server, bool $always, \Closure $source): void
    {
        try {
            if (self::$replacement instanceof \Closure) {
                (self::$replacement)($server, $always);

                return;
            }
            if (!$server->reportsServerInfo || $server->projectId === '' || !$server->options->enableTracing) {
                return;
            }
            $clock = self::$clock ??= Clock::monotonic();
            if (!Guard::open($always, $clock())) {
                return;
            }
            [$name] = $server->serverInfo();
            if (!Guard::claim($server->projectId, $name ?? '', $clock())) {
                return;
            }
            $server->publisher()->queue()->defer('server-info:' . $server->projectId . ':' . $name, new PendingHeartbeat($server, $source(), $clock));
        } catch (\Throwable $e) {
            Log::write('Skipped the server info heartbeat: the check failed - ' . $e::class);
        }
    }
}
