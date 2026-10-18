<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * How long a framework's request-end hook waits for delivery (D58, plan E1 header conflict 13). A process that
 * outlives the request — Octane on Swoole or RoadRunner, a Symfony Runtime worker, FrankenPHP worker mode — waits at
 * most `WORKER_SECONDS`, so the worker is soon free for the next request. Anywhere else the hook only starts the
 * delivery (0: one non-blocking pass): a per-request SAPI runs AgentCat's shutdown function after the hook, and a
 * console command ends with the process; both deliver within the queue's one `EventQueue::SHUTDOWN_TIMEOUT_SECONDS`
 * deadline (D47).
 *
 * The wait is `EventQueue::flush()`: a window of its own that fixes no shutdown deadline, keeps the queue open, ticks
 * the companions without finishing them, and waits for events and exporter requests but not for diagnostics. What is
 * left — waiting events, transfers in flight, retries scheduled for later — moves on at the next request's `pump()`
 * and wait, and at the worker's stop, whose `shutdown()` fixes the single 5 s deadline and keeps its last 10 % (at
 * most 0.5 s) for the companions' `finish()`.
 *
 * @internal
 */
final class RequestEndWait
{
    /** D58: the longest wait for delivery after each request in a process that outlives the request. */
    public const float WORKER_SECONDS = 1.0;

    /**
     * @param bool                                    $console true for a console command (Laravel `runningInConsole()`;
     *                                                         Octane workers report false); Symfony's kernel.terminate passes false
     * @param array<array-key, mixed>|null            $server  `$_SERVER`; null reads it
     * @param (\Closure(string): (string|false))|null $getenv  default `getenv(...)`
     */
    public static function seconds(bool $console, string $sapi = PHP_SAPI, ?array $server = null, ?\Closure $getenv = null): float
    {
        if ($console) {
            return 0.0;
        }
        $outlivesRequest = $sapi === 'cli' || $sapi === 'phpdbg'
            || ($sapi === 'frankenphp' && FinishRequest::isFrankenPhpWorker($server ?? $_SERVER, $getenv));

        return $outlivesRequest ? self::WORKER_SECONDS : 0.0;
    }
}
