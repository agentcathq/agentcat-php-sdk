<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\RepeatedLines;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Transport\ApiBaseUrl;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\CurlSupport;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\Transport;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Internal\Wire\WireMapper;

/**
 * The process-global event queue (TypeScript `eventQueue`). `add()` only stores the event (D04); hooks,
 * hygiene and HTTP run in `pump()`, `flush()` or `shutdown()`. At most 5 events are in delivery at once, and
 * at most 10 000 wait (the oldest is dropped). No method throws.
 *
 * An event's hooks run when it gets a free delivery slot, except in a `flush()` with a window, which runs them for
 * every waiting event first, whatever the free slots: a worker's request-end wait is such a `flush()`, so each hook runs
 * in its own request, also in a worker that serves the next request in the same process (Octane). The payloads then
 * wait, in order, only for the transport. A 0 s `flush()` stays one non-blocking pass.
 *
 * The queue also drives the side channel of the exporter and diagnostics requests (`channel()`) and its companions
 * (`attach()`): every pass ticks each companion (except the pass after `finish()`), then pumps the channel. `flush()`
 * waits for the exporter requests inside its timeout, `shutdown()` for the background ones (diagnostics) too.
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

    /** Short waits when both the events and the side channel have transfers, so neither waits on the other's sockets. */
    private const float SLICE_SECONDS = 0.01;

    /** With companions, the end of the shutdown budget is kept for `finish()`: this share of it, at most the cap. */
    private const float FINISH_RESERVE_SHARE = 0.1;

    private const float FINISH_RESERVE_MAX_SECONDS = 0.5;

    private static ?self $global = null;

    /** @var \SplQueue<QueuedEvent> events whose hooks have not run */
    private readonly \SplQueue $waiting;

    /** @var \SplQueue<ReadyEvent> events through the pipeline (`flush()`), each older than every waiting event */
    private readonly \SplQueue $ready;

    /** @var \Closure(Event): string */
    private readonly \Closure $encode;

    private string $endpoint;

    private bool $accepting = true;

    private bool $signalWarningLogged = false;

    /** True while `pump()` or `flush()` runs: guards against re-entrant calls from hooks and exporters. */
    private bool $busy = false;

    /** Fixed by the first `shutdown()`: later shutdowns deliver only until then (one budget per queue). */
    private ?float $shutdownDeadline = null;

    /** @var list<QueueCompanion> */
    private array $companions = [];

    /**
     * @param (\Closure(): void)|null        $onEnqueue the delivery-trigger registrar, called on every `add()` and `arm()`
     * @param (\Closure(Event): string)|null $encode    builds the HTTP body; default `WireEncoder::encode(...)`
     * @param HttpChannel                    $channel   the side channel of the exporter and diagnostics requests
     * @param SignalDrain|null               $signalDrain the SIGTERM and SIGINT drain that `disableSignalHandlers()` switches off
     */
    public function __construct(
        private readonly EventProcessor $processor = new EventProcessor(),
        private readonly Transport $transport = new CurlTransport(),
        private ExporterSink $exporters = new NullExporterSink(),
        private readonly ?\Closure $onEnqueue = null,
        private readonly float $shutdownTimeoutSeconds = self::SHUTDOWN_TIMEOUT_SECONDS,
        ?\Closure $encode = null,
        private readonly HttpChannel $channel = new CurlHttpChannel(),
        private readonly ?SignalDrain $signalDrain = null,
    ) {
        $this->waiting = new \SplQueue();
        $this->ready = new \SplQueue();
        $this->encode = $encode ?? WireEncoder::encode(...);
        $this->endpoint = ApiBaseUrl::endpoint(ApiBaseUrl::resolve()); // environment or default, until configure()
    }

    /**
     * The queue of this process; its first event registers the shutdown delivery (which ends the request first on a
     * web SAPI, `RequestEnd`) and installs the signal drain (CLI servers with ext-pcntl).
     */
    public static function global(): self
    {
        if (!self::$global instanceof self) {
            $finish = FinishRequest::detect();
            $flusher = new ShutdownFlusher(static fn(): self => self::global(), null, $finish, RequestEnd::detect($finish instanceof \Closure));
            $signals = new SignalDrain(static fn(): self => self::global());
            self::$global = new self(onEnqueue: static function () use ($flusher, $signals): void {
                $flusher->ensureRegistered();
                $signals->install();
            }, signalDrain: $signals);
        }

        return self::$global;
    }

    /** Keeps the signal handlers out of the process. False when they are installed already. */
    public function disableSignalHandlers(): bool
    {
        return $this->signalDrain?->disable() ?? true;
    }

    /** True once per queue (one per process for the global queue): for the "ignored" warning of `disableSignalHandlers`. */
    public function claimSignalHandlersWarning(): bool
    {
        $first = !$this->signalWarningLogged;
        $this->signalWarningLogged = true;

        return $first;
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

    public function exporterSink(): ExporterSink
    {
        return $this->exporters;
    }

    /** The side channel: exporters and diagnostics post their requests here. */
    public function channel(): HttpChannel
    {
        return $this->channel;
    }

    public function attach(QueueCompanion $companion): void
    {
        $this->companions[] = $companion;
    }

    /** The delivery triggers (shutdown function, signal drain) without an event: diagnostics lines need them too. */
    public function arm(): void
    {
        if (!$this->onEnqueue instanceof \Closure) {
            return;
        }

        try {
            ($this->onEnqueue)();
        } catch (\Throwable $e) {
            Log::write('Failed to register AgentCat event delivery as a background task: ' . ErrorText::of($e));
        }
    }

    /** Anything left to send: an event, a side request, or a companion's buffer. True when a check throws. */
    public function hasWork(): bool
    {
        try {
            if ($this->queued() > 0 || $this->transport->inFlight() > 0 || $this->channel->pending() > 0) {
                return true;
            }
            foreach ($this->companions as $companion) {
                if ($companion->hasWork()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /** Stores the event. Nothing else runs on the request path. */
    public function add(QueuedEvent $event): void
    {
        if (!$this->accepting) {
            Log::write('Queue is shutting down, event dropped');

            return;
        }
        if ($this->queued() >= self::MAX_QUEUE_SIZE) {
            Log::write('Event queue full, dropping oldest event');
            $this->ready->isEmpty() ? $this->waiting->dequeue() : $this->ready->dequeue();
        }
        $this->waiting->enqueue($event);
        $this->arm();
    }

    /**
     * Non-blocking: starts the events queued when it began while fewer than 5 are in delivery (the pipeline of a
     * waiting one runs then), pumps the transport, ticks the companions and pumps the side channel. An event a hook
     * queues meanwhile waits for the next pump. A re-entrant call (from a hook or an exporter) does nothing.
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
     * Blocking: delivers the events and the side channel's exporter requests until no event waits or is in flight
     * and no exporter request is pending (true), or the timeout passes (false); null = no limit. The background
     * requests (diagnostics) are neither counted nor waited for: they go on in later passes and at `shutdown()`. A
     * re-entrant call (from a hook or an exporter) returns false at once.
     *
     * With a window (a positive timeout, or none), each pass ends with the pipeline (the hooks first) of the events that
     * waited when it began, whatever the free slots: so the hooks of those events run before the call returns, also
     * when the deadline has passed. An event a hook queues meanwhile goes to the next pass, so a hook that keeps queueing
     * cannot hold the call past its deadline. Without a window (0, negative or NaN) it is one non-blocking pass, which
     * runs only the hooks of the events it starts; the others run later, in the shutdown function of the same request
     * or process at the latest.
     */
    public function flush(?float $timeoutSeconds = null): bool
    {
        return $this->deliverUntil(fn(): float => $timeoutSeconds === null ? INF : $this->transport->now() + $this->budget($timeoutSeconds), background: false, prepare: $timeoutSeconds === null || $timeoutSeconds > 0);
    }

    /**
     * Stops accepting events, then delivers the events and the side requests, background ones included, for at most
     * the shutdown timeout (TypeScript `destroy()`). The first call fixes the deadline; a later call delivers only until
     * that same deadline, and dispatches nothing once it has passed. So a signal drain and the shutdown function after
     * it, or a signal during the shutdown function's flush, share one budget.
     *
     * With companions, the end of the budget is kept for them: 10 % of the shutdown timeout, at most 0.5 s. The
     * delivery stops when everything is delivered or only that reserve is left. Then each companion finishes
     * (diagnostics posts its buffer, also when a hung exporter or a slow AgentCat API used the rest of the budget), and
     * one more pass, to the full deadline, sends what they posted. That pass ticks no companion, so `finish()` is a
     * companion's last call in a shutdown. A call whose delivery never started (a re-entrant call from a hook or an
     * exporter, or a clock that cannot be read) finishes no companion.
     */
    public function shutdown(): void
    {
        $this->accepting = false;
        $deadline = fn(): float => $this->shutdownDeadline ??= $this->transport->now() + $this->budget($this->shutdownTimeoutSeconds);
        if (!$this->shutdownDeadlinePassed()) {
            if ($this->companions === []) {
                $this->deliverUntil($deadline);
            } else {
                $this->deliverWithCompanions($deadline);
            }
        }
        if ($this->queued() > 0) {
            Log::write(sprintf('Shutting down with %d events still in queue', $this->queued()));
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
        return ['queueLength' => $this->queued(), 'activeRequests' => $this->transport->inFlight()];
    }

    /** The events not yet in delivery: those that went through the pipeline, then those whose hooks have not run. */
    private function queued(): int
    {
        return $this->ready->count() + $this->waiting->count();
    }

    /**
     * True when an earlier `shutdown()` fixed the deadline and that deadline has passed.
     *
     * @phpstan-impure it reads the clock
     */
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
     * The shutdown delivery with companions: up to the finish reserve, then each companion's `finish()`, then the
     * last pass, to the full deadline, which ticks none of them.
     *
     * @param \Closure(): float $deadline fixes the shared deadline at its first call
     */
    private function deliverWithCompanions(\Closure $deadline): void
    {
        $this->deliverUntil(fn(): float => $deadline() - $this->finishReserve());
        // Only after a delivery that ran: a re-entrant call finds the guard still taken by the frame it runs in, and
        // an unreadable clock left the deadline unset.
        if (!$this->busy && $this->shutdownDeadline !== null && !$this->shutdownDeadlinePassed()) {
            $this->finishCompanions();
            $this->deliverUntil($deadline, tick: false);
        }
    }

    /** 10 % of the shutdown budget, at most 0.5 s; never NaN, as `budget()` is not. */
    private function finishReserve(): float
    {
        return min(self::FINISH_RESERVE_MAX_SECONDS, self::FINISH_RESERVE_SHARE * $this->budget($this->shutdownTimeoutSeconds));
    }

    /**
     * The delivery loop of `flush()` and `shutdown()`: done when no event waits or is in flight and no side request is
     * pending (the background ones count only with `$background`). A re-entrant call returns false at once.
     *
     * @param \Closure(): float $deadline   read once, after the re-entrancy guard is taken
     * @param bool              $tick       false after `finish()`: the passes tick no companion
     * @param bool              $background whether it waits for the background requests (diagnostics): `shutdown()` does
     * @param bool              $prepare    whether each pass ends with the pipeline of the waiting events: a `flush()` with a window
     */
    private function deliverUntil(\Closure $deadline, bool $tick = true, bool $background = true, bool $prepare = false): bool
    {
        if ($this->busy) {
            return false;
        }
        $this->busy = true;
        RepeatedLines::begin(); // a failure of every pass is logged once

        try {
            $until = $deadline();
            while (true) {
                $this->pumpOnce($tick);
                if ($prepare) {
                    $this->prepareWaiting();
                }
                if ($this->queued() === 0 && $this->transport->inFlight() === 0 && $this->channel->pending($background) === 0) {
                    return true;
                }
                if ($this->transport->now() >= $until) {
                    return false;
                }
                // Slots freed by the last pump are refilled at once; waiting would sleep with idle slots.
                if ($this->queued() === 0 || $this->transport->inFlight() >= self::CONCURRENCY) {
                    $this->wait($until);
                }
            }
        } catch (\Throwable $e) {
            Log::write(self::GAVE_UP . ErrorText::of($e));

            return false;
        } finally {
            $this->busy = false;
            RepeatedLines::end();
        }
    }

    /**
     * Waits on the transport alone, or, while side requests are pending, a slice on each that never ends past `$until`.
     * `$until` is never NaN. The time left goes through `CurlSupport::waitSeconds()`: within 0 and the slice, and 0 for
     * the NaN of INF - INF (a test clock that jumped to an INF deadline).
     */
    private function wait(float $until): void
    {
        if ($this->channel->pending() === 0) {
            $this->transport->waitForActivity($until);

            return;
        }
        $this->transport->waitForActivity(min($until, $this->transport->now() + self::SLICE_SECONDS));
        $this->channel->waitForActivity(CurlSupport::waitSeconds($until - $this->transport->now(), self::SLICE_SECONDS));
    }

    /**
     * One pass: starts the events queued when it began while fewer than 5 are in delivery, oldest first (those through
     * the pipeline, then the waiting ones, whose pipeline runs now), and pumps the transport, then ticks each companion
     * (unless `$tick` is false) and pumps the side channel. Never throws.
     *
     * An event a hook queues meanwhile waits for the next pass: an event that starts no transfer (telemetry only, an
     * encode failure, dropped by a hook) leaves the slots free, so a hook that keeps queueing such events cannot hold
     * the pass. A signal drain during a hook (`finalShutdown()`) delivers the whole queue: the pass stops when the
     * queue is empty.
     */
    private function pumpOnce(bool $tick = true): void
    {
        try {
            for ($left = $this->queued(); $left > 0 && $this->queued() > 0 && $this->transport->inFlight() < self::CONCURRENCY; --$left) {
                $ready = $this->ready->isEmpty() ? $this->prepare($this->waiting->dequeue()) : $this->ready->dequeue();
                if ($ready instanceof ReadyEvent) {
                    $this->send($ready);
                }
            }
            $this->transport->pump();
        } catch (\Throwable $e) {
            RepeatedLines::write(self::GAVE_UP . ErrorText::of($e));
        }
        if ($tick) {
            foreach ($this->companions as $companion) {
                try {
                    $companion->tick();
                } catch (\Throwable) {
                    // a companion never stops the queue
                }
            }
        }

        try {
            $this->channel->pump(); // CurlHttpChannel never throws; a test double's callback might
        } catch (\Throwable) {
            // the side channel never stops the queue
        }
    }

    private function finishCompanions(): void
    {
        foreach ($this->companions as $companion) {
            try {
                $companion->finish(); // diagnostics posts the lines of the drain too (D07)
            } catch (\Throwable) {
                // a companion never stops the shutdown
            }
        }
    }

    /**
     * The pipeline of the events that waited when the pass began, whatever the free slots (`flush()`): the payloads wait
     * in order. An event a hook queues meanwhile waits for the next pass. A signal drain during a hook
     * (`finalShutdown()`) delivers the whole queue: the pass stops when no event waits.
     */
    private function prepareWaiting(): void
    {
        for ($left = $this->waiting->count(); $left > 0 && !$this->waiting->isEmpty(); --$left) {
            $ready = $this->prepare($this->waiting->dequeue());
            if ($ready instanceof ReadyEvent) {
                $this->ready->enqueue($ready);
            }
        }
    }

    /** TypeScript `processEvent` stages 0-5 (the hooks first), and the body; null when the pipeline dropped the event. */
    private function prepare(QueuedEvent $queued): ?ReadyEvent
    {
        $event = $this->processor->process($queued);
        if (!$event instanceof Event) {
            return null;
        }
        if (($event->projectId ?? '') === '') {
            return new ReadyEvent($event, null);
        }

        try {
            return new ReadyEvent($event, ($this->encode)($event));
        } catch (\Throwable $e) {
            return new ReadyEvent($event, null, ErrorText::of($e));
        }
    }

    /**
     * TypeScript `sendEvent`: the exporters alone for an event without a body, else the delivery to the API endpoint of
     * this moment (the last `configure()` wins).
     */
    private function send(ReadyEvent $ready): void
    {
        $event = $ready->event;
        if ($ready->body === null) {
            $this->export($event);
            if ($ready->encodeError !== null) {
                Log::write(sprintf('Failed to encode event %s: %s', $event->id, $ready->encodeError));
            }

            return;
        }
        foreach (WireMapper::droppedFields($event) as $key) {
            Log::write(sprintf('WARN: Event %s: %s is not a JSON object or array and was not sent', $event->id, $key));
        }
        $this->transport->start(Delivery::forEvent($event, $this->endpoint, $ready->body, function () use ($event): void {
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
