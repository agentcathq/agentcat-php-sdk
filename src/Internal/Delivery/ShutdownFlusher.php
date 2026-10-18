<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * Delivers the global queue when the request or script ends.
 *
 * The first event (or `EventQueue::arm()`) registers `onShutdown()` as a shutdown function. It registers `onFinalShutdown()` again, so
 * that the flush runs after every shutdown function the application registered later (OpenTelemetry's
 * `ShutdownHandler` pattern). When the queue has work (events, exporter requests or diagnostics lines), the final
 * pass first ends the request (`RequestEnd`: the session, the time limit and, without a finish call, the output
 * buffers) and sends the HTTP response to the client when the SAPI can (`fastcgi_finish_request()` and friends), then
 * shuts the queue down within its drain budget.
 *
 * @internal
 */
final class ShutdownFlusher
{
    /** @var \Closure(\Closure(): void): void */
    private readonly \Closure $register;

    private bool $registered = false;

    /**
     * @param \Closure(): EventQueue                   $queue         the queue to deliver, looked up at shutdown
     * @param (\Closure(\Closure(): void): void)|null $register      default `register_shutdown_function(...)`
     * @param (\Closure(): void)|null                 $finishRequest sends the response to the client (`FinishRequest::detect()`)
     * @param RequestEnd|null                         $requestEnd    runs before the finish call when the queue has work
     */
    public function __construct(
        private readonly \Closure $queue,
        ?\Closure $register = null,
        private readonly ?\Closure $finishRequest = null,
        private readonly ?RequestEnd $requestEnd = null,
    ) {
        $this->register = $register ?? register_shutdown_function(...);
    }

    /** Registers the shutdown function once. A registrar error reaches the caller (the queue logs it). */
    public function ensureRegistered(): void
    {
        if ($this->registered) {
            return;
        }
        ($this->register)($this->onShutdown(...));
        $this->registered = true;
    }

    /** First shutdown pass: moves the flush behind the shutdown functions registered after ours. */
    public function onShutdown(): void
    {
        try {
            ($this->register)($this->onFinalShutdown(...));
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
