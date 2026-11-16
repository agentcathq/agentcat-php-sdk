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
 * The adapters call a check when the handler of a `tools/list` or `tools/call` request has answered. A check reads
 * memory only (`Guard`), calls no code that a customer can replace, and never throws. When a heartbeat is due, it keeps
 * the request's context in a `PendingHeartbeat` and hands that to the event queue of the server, which runs it in its
 * window after the response of the same request, after the events (`EventQueue::defer()`), or drops it there. Nothing
 * of a heartbeat runs on the path of a request, and no work waits for a later request. No timer exists: a server
 * without requests sends nothing.
 *
 * A process whose queue has no window after the response sends no heartbeat, and a check ends there at once
 * (`EventQueue::canDefer()`): a stdio server, a worker loop without a framework bridge, and a SAPI that sends the
 * response only when the script ends (mod_php, php-cgi, `php -S`).
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
     * @param \Closure(): ?string          $name   the server name, for the key of the guard; it must read memory only
     * @param \Closure(): ServerInfoSource $source the server's lists with the context of this request; called only when
     *                                             a heartbeat is due, and it must only keep that context, not use it
     */
    public static function onToolList(TrackedServer $server, \Closure $name, \Closure $source): void
    {
        self::check($server, true, $name, $source);
    }

    /**
     * The check of an answered `tools/call` request. A call of AgentCat's `get_more_tools` is a feedback note.
     *
     * @param \Closure(): ?string          $name   as for `onToolList()`
     * @param \Closure(): ServerInfoSource $source as for `onToolList()`
     */
    public static function onToolCall(TrackedServer $server, string $toolName, \Closure $name, \Closure $source): void
    {
        self::check($server, $server->options->enableReportMissing && $toolName === GetMoreTools::NAME, $name, $source);
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

    /**
     * @param \Closure(): ?string          $name
     * @param \Closure(): ServerInfoSource $source
     */
    private static function check(TrackedServer $server, bool $always, \Closure $name, \Closure $source): void
    {
        try {
            if (self::$replacement instanceof \Closure) {
                (self::$replacement)($server, $always);

                return;
            }
            if (!$server->reportsServerInfo || $server->projectId === '' || !$server->options->enableTracing) {
                return;
            }
            $queue = $server->publisher()->queue();
            if (!$queue->canDefer()) {
                return; // no window after the response in this process: no heartbeat, and nothing is noted
            }
            $clock = self::$clock ??= Clock::monotonic();
            if (!Guard::open($always, $clock())) {
                return;
            }
            $key = $name() ?? '';
            if (!Guard::claim($server->projectId, $key, $clock())) {
                return;
            }
            $queue->defer(Guard::key($server->projectId, $key), new PendingHeartbeat($server, $source(), $clock));
        } catch (\Throwable $e) {
            Log::write('Skipped the server info heartbeat: the check failed - ' . ErrorName::of($e));
        }
    }
}
