<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Transport\ApiBaseUrl;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\Transport;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Internal\Wire\WireMapper;

/**
 * The process-global event queue (TypeScript `eventQueue`). `add()` only stores the event (D04); hooks,
 * hygiene and HTTP run in `pump()`, `flush()` or `shutdown()`. At most 5 events are in delivery at once, and
 * at most 10 000 wait (the oldest is dropped). No method throws.
 *
 * @internal
 */
final class EventQueue
{
    public const int MAX_QUEUE_SIZE = 10_000;

    public const int CONCURRENCY = 5;

    /** TypeScript `destroy()`: 5 000 ms. */
    public const float SHUTDOWN_TIMEOUT_SECONDS = 5.0;

    private const string GAVE_UP = 'Failed to deliver AgentCat event after retries: ';

    private static ?self $global = null;

    /** @var \SplQueue<QueuedEvent> events not yet in delivery */
    private readonly \SplQueue $waiting;

    /** @var \Closure(Event): string */
    private readonly \Closure $encode;

    private string $endpoint;

    private bool $accepting = true;

    /** True while `pump()` or `flush()` runs: guards against re-entrant calls from hooks and exporters. */
    private bool $busy = false;

    /** Fixed by the first `shutdown()`: later shutdowns deliver only until then (one budget per queue). */
    private ?float $shutdownDeadline = null;

    /**
     * @param (\Closure(): void)|null        $onEnqueue the delivery-trigger registrar, called on every `add()`
     * @param (\Closure(Event): string)|null $encode    builds the HTTP body; default `WireEncoder::encode(...)`
     */
    public function __construct(
        private readonly EventProcessor $processor = new EventProcessor(),
        private readonly Transport $transport = new CurlTransport(),
        private ExporterSink $exporters = new NullExporterSink(),
        private readonly ?\Closure $onEnqueue = null,
        private readonly float $shutdownTimeoutSeconds = self::SHUTDOWN_TIMEOUT_SECONDS,
        ?\Closure $encode = null,
    ) {
        $this->waiting = new \SplQueue();
        $this->encode = $encode ?? WireEncoder::encode(...);
        $this->endpoint = ApiBaseUrl::endpoint(ApiBaseUrl::resolve()); // environment or default, until configure()
    }

    /**
     * The queue of this process; its first event registers the shutdown delivery and installs the signal drain
     * (CLI servers with ext-pcntl).
     */
    public static function global(): self
    {
        if (!self::$global instanceof self) {
            $flusher = new ShutdownFlusher(static fn(): self => self::global(), null, FinishRequest::detect());
            $signals = new SignalDrain(static fn(): self => self::global());
            self::$global = new self(onEnqueue: static function () use ($flusher, $signals): void {
                $flusher->ensureRegistered();
                $signals->install();
            });
        }

        return self::$global;
    }

    /** Tests only: replaces the global queue; null makes `global()` build a new one. */
    public static function setGlobal(?self $queue): void
    {
        self::$global = $queue;
    }

    /** Option > `AGENTCAT_API_URL` > `MCPCAT_API_URL` > default. The last call wins (TypeScript `configure`). */
    public function configure(?string $apiBaseUrlOption): void
    {
        $this->endpoint = ApiBaseUrl::endpoint(ApiBaseUrl::resolve($apiBaseUrlOption));
    }

    public function setExporterSink(ExporterSink $exporters): void
    {
        $this->exporters = $exporters;
    }

    /** Stores the event. Nothing else runs on the request path. */
    public function add(QueuedEvent $event): void
    {
        if (!$this->accepting) {
            Log::write('Queue is shutting down, event dropped');

            return;
        }
        if ($this->waiting->count() >= self::MAX_QUEUE_SIZE) {
            Log::write('Event queue full, dropping oldest event');
            $this->waiting->dequeue();
        }
        $this->waiting->enqueue($event);
        if ($this->onEnqueue instanceof \Closure) {
            try {
                ($this->onEnqueue)();
            } catch (\Throwable $e) {
                Log::write('Failed to register AgentCat event delivery as a background task: ' . ErrorText::of($e));
            }
        }
    }

    /**
     * Non-blocking: runs the pipeline for waiting events while fewer than 5 are in delivery, then pumps the
     * transport. A re-entrant call (from a hook or an exporter) does nothing.
     *
     * `pump()` is non-blocking only when libcurl resolves host names asynchronously (threaded resolver or
     * c-ares). With a synchronous resolver, starting a transfer can block for the DNS lookup.
     */
    public function pump(): void
    {
        if ($this->busy) {
            return;
        }
        $this->busy = true;
        $this->pumpOnce(); // never throws
        $this->busy = false;
    }

    /**
     * Blocking: delivers until nothing waits or is in flight (true) or the timeout passes (false); null = no
     * limit. A re-entrant call (from a hook or an exporter) returns false at once.
     */
    public function flush(?float $timeoutSeconds = null): bool
    {
        return $this->deliverUntil(fn(): float => $timeoutSeconds === null ? INF : $this->transport->now() + $this->budget($timeoutSeconds));
    }

    /**
     * Stops accepting events, then delivers for at most the shutdown timeout (TypeScript `destroy()`). The first call
     * fixes the deadline; a later call delivers only until that same deadline, and dispatches nothing once it has
     * passed. So a signal drain and the shutdown function after it, or a signal during the shutdown function's flush,
     * share one budget.
     */
    public function shutdown(): void
    {
        $this->accepting = false;
        if (!$this->shutdownDeadlinePassed()) {
            $this->deliverUntil(fn(): float => $this->shutdownDeadline ??= $this->transport->now() + $this->budget($this->shutdownTimeoutSeconds));
        }
        if (!$this->waiting->isEmpty()) {
            Log::write(sprintf('Shutting down with %d events still in queue', $this->waiting->count()));
        }
    }

    /**
     * `shutdown()` from the last shutdown function or from the signal drain. An `exit()` or a fatal error inside a
     * `pump()` or `flush()` frame (in a hook or an exporter) skips the rest of it, `finally` included, and leaves the
     * re-entrancy guard set; a signal can arrive while such a frame runs. Clear the guard first, so that the waiting
     * events are still delivered.
     */
    public function finalShutdown(): void
    {
        $this->busy = false;
        $this->shutdown();
    }

    /** @return array{queueLength: int, activeRequests: int} */
    public function stats(): array
    {
        return ['queueLength' => $this->waiting->count(), 'activeRequests' => $this->transport->inFlight()];
    }

    /** True when an earlier `shutdown()` fixed the deadline and that deadline has passed. */
    private function shutdownDeadlinePassed(): bool
    {
        try {
            return $this->shutdownDeadline !== null && $this->transport->now() >= $this->shutdownDeadline;
        } catch (\Throwable) {
            return false; // the delivery loop logs the transport's failure
        }
    }

    /** A NaN or negative timeout means "no time left". max() is no guard: max(0.0, NAN) is NaN on PHP 8.4+. */
    private function budget(float $timeoutSeconds): float
    {
        return is_nan($timeoutSeconds) ? 0.0 : max(0.0, $timeoutSeconds);
    }

    /**
     * The delivery loop of `flush()` and `shutdown()`. A re-entrant call returns false at once.
     *
     * @param \Closure(): float $deadline read once, after the re-entrancy guard is taken
     */
    private function deliverUntil(\Closure $deadline): bool
    {
        if ($this->busy) {
            return false;
        }
        $this->busy = true;

        try {
            $until = $deadline();
            while (true) {
                $this->pumpOnce();
                if ($this->waiting->isEmpty() && $this->transport->inFlight() === 0) {
                    return true;
                }
                if ($this->transport->now() >= $until) {
                    return false;
                }
                // Slots freed by the last pump are refilled at once; waiting would sleep with idle slots.
                if ($this->waiting->isEmpty() || $this->transport->inFlight() >= self::CONCURRENCY) {
                    $this->transport->waitForActivity($until);
                }
            }
        } catch (\Throwable $e) {
            Log::write(self::GAVE_UP . ErrorText::of($e));

            return false;
        } finally {
            $this->busy = false;
        }
    }

    private function pumpOnce(): void
    {
        try {
            while (!$this->waiting->isEmpty() && $this->transport->inFlight() < self::CONCURRENCY) {
                $this->dispatch($this->waiting->dequeue());
            }
            $this->transport->pump();
        } catch (\Throwable $e) {
            Log::write(self::GAVE_UP . ErrorText::of($e));
        }
    }

    /** TypeScript `processEvent` stages 0-5, then `sendEvent`. */
    private function dispatch(QueuedEvent $queued): void
    {
        $event = $this->processor->process($queued);
        if (!$event instanceof Event) {
            return;
        }
        if (($event->projectId ?? '') === '') {
            $this->export($event);

            return;
        }

        try {
            $body = ($this->encode)($event);
        } catch (\Throwable $e) {
            $this->export($event);
            Log::write(sprintf('Failed to encode event %s: %s', $event->id, ErrorText::of($e)));

            return;
        }
        foreach (WireMapper::droppedFields($event) as $key) {
            Log::write(sprintf('WARN: Event %s: %s is not a JSON object or array and was not sent', $event->id, $key));
        }
        $this->transport->start(Delivery::forEvent($event, $this->endpoint, $body, function () use ($event): void {
            $this->export($event);
        }));
    }

    private function export(Event $event): void
    {
        try {
            $this->exporters->export($event);
        } catch (\Throwable $e) {
            Log::write('Telemetry export error: ' . ErrorText::of($e));
        }
    }
}
