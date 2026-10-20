<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Internal\Logging\Log;

/**
 * Delivers the global queue when the request or script ends.
 *
 * The first event (or `EventQueue::arm()`) registers `onShutdown()` as a shutdown function. It registers `onFinalShutdown()` again, so
 * that the flush runs after every shutdown function the application registered later (OpenTelemetry's
 * `ShutdownHandler` pattern). When the queue has work (events, exporter requests or diagnostics lines), the final
 * pass first ends the request (`RequestEnd`: the session, the time limit and, without a finish call, the output
 * buffers) and sends the HTTP response to the client when the SAPI can (`fastcgi_finish_request()` and friends), then
 * shuts the queue down within its drain budget. Where a host disables `register_shutdown_function()`
 * (`disable_functions`), nothing is registered and one line says so: the events still waiting when the script ends are
 * lost.
 *
 * @internal
 */
final class ShutdownFlusher
{
    private const string NO_SHUTDOWN = 'WARN: AgentCat cannot send waiting events when the script ends: register_shutdown_function is disabled';

    /** @var (\Closure(\Closure(): void): void)|null null where a host disabled `register_shutdown_function()` */
    private readonly ?\Closure $register;

    private bool $registered = false;

    /**
     * @param \Closure(): EventQueue                   $queue         the queue to deliver, looked up at shutdown
     * @param (\Closure(\Closure(): void): void)|null $register      default `register_shutdown_function(...)`
     * @param (\Closure(): void)|null                 $finishRequest sends the response to the client (`FinishRequest::detect()`)
     * @param RequestEnd|null                         $requestEnd    runs before the finish call when the queue has work
     * @param (\Closure(string): bool)|null           $functionExists tests only: tells whether `register_shutdown_function()` exists; default `function_exists()`
     */
    public function __construct(
        private readonly \Closure $queue,
        ?\Closure $register = null,
        private readonly ?\Closure $finishRequest = null,
        private readonly ?RequestEnd $requestEnd = null,
        ?\Closure $functionExists = null,
    ) {
        $this->register = $register ?? (($functionExists ?? function_exists(...))('register_shutdown_function') ? register_shutdown_function(...) : null);
    }

    /**
     * Registers the shutdown function once; without `register_shutdown_function()`, logs NO_SHUTDOWN once instead. A
     * registrar error reaches the caller (the queue logs it).
     */
    public function ensureRegistered(): void
    {
        if ($this->registered) {
            return;
        }
        if (!$this->register instanceof \Closure) {
            $this->registered = true;
            Log::write(self::NO_SHUTDOWN);

            return;
        }
        ($this->register)($this->onShutdown(...));
        $this->registered = true;
    }

    /** First shutdown pass: moves the flush behind the shutdown functions registered after ours. */
    public function onShutdown(): void
    {
        try {
            ($this->register ?? static function (\Closure $pass): void {
                $pass(); // no registrar: the final pass runs now
            })($this->onFinalShutdown(...));
        } catch (\Throwable) {
            $this->onFinalShutdown(); // cannot move to the end of the list: flush now
        }
    }

    /** Final pass. A shutdown function must never throw. */
    public function onFinalShutdown(): void
    {
        try {
            $queue = ($this->queue)();
            if ($queue->hasWork()) {
                $this->requestEnd?->prepare(EventQueue::SHUTDOWN_TIMEOUT_SECONDS); // never throws
                if ($this->finishRequest instanceof \Closure) {
                    try {
                        ($this->finishRequest)(); // the client gets its response before the events are sent
                    } catch (\Throwable) {
                        // keep going: delivery matters more than the finish call
                    }
                }
            }
            $queue->finalShutdown();
        } catch (\Throwable) {
            // a shutdown function must never throw
        }
    }
}
