<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\QueueCompanion;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\Env;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Internal\Transport\HttpRequest;

/**
 * TypeScript diagnostics: every SDK log line (D07), as OTLP/JSON logs to AgentCat's collector. The first `init()` of
 * the process decides. Lines are buffered (1 000 at most, the oldest dropped) and posted at the first queue pass
 * (`pump()`, `flush()`, `shutdown()`) once the oldest of them is 2 s old, and once more when the queue shuts down, after
 * the events (D47: under PHP-FPM one post per request, after the response). Lines logged in the last shutdown pass,
 * after `finish()`, are dropped unless a later `shutdown()` runs. A failure is silent: a log line would feed the buffer
 * it reports on.
 *
 * @internal
 */
final class Diagnostics implements QueueCompanion
{
    public const int MAX_BUFFER = 1000;

    public const float BATCH_FLUSH_SECONDS = 2.0;

    private const string IGNORED = 'WARN: AgentCat diagnostics were set up by an earlier track(); disableDiagnostics: true in this call is ignored';

    private static bool $initialized = false;

    private static bool $warned = false;

    private static ?self $active = null;

    /** @var list<array{timeUnixNano: string, severityNumber: int, severityText: string, body: array{stringValue: string}, attributes: list<array{key: string, value: array{stringValue: string}}>}> */
    private array $buffer = [];

    /** The clock reading of the oldest buffered line; null while the buffer is empty. */
    private ?float $firstAt = null;

    private bool $armed = false;

    /**
     * @param list<array{key: string, value: array{stringValue: string}}> $resource
     * @param list<array{key: string, value: array{stringValue: string}}> $recordAttributes
     * @param \Closure(): float                                           $clock            monotonic seconds
     * @param \Closure(): int                                             $wallMillis       wall-clock milliseconds
     */
    private function __construct(
        private readonly EventQueue $queue,
        private readonly array $resource,
        private readonly array $recordAttributes,
        private readonly \Closure $clock,
        private readonly \Closure $wallMillis,
    ) {}

    /**
     * TS `initDiagnostics`: the first call in the process decides; a later call changes nothing. A later
     * `$disabled = true` while diagnostics run logs one line, once. Never throws.
     *
     * @param (\Closure(): bool)|null  $inTestEnvironment default `TestMode::inTestEnvironment()` (P5)
     * @param (\Closure(): float)|null $clock             tests: monotonic seconds
     * @param (\Closure(): int)|null   $wallMillis        tests: wall-clock milliseconds
     */
    public static function init(string $projectId, bool $disabled, EventQueue $queue, ?\Closure $inTestEnvironment = null, ?\Closure $clock = null, ?\Closure $wallMillis = null): void
    {
        try {
            if (self::$initialized) {
                if ($disabled && self::$active instanceof self && !self::$warned) {
                    self::$warned = true;
                    Log::write(self::IGNORED);
                }

                return;
            }
            self::$initialized = true;
            $testEnvironment = $inTestEnvironment instanceof \Closure ? $inTestEnvironment() : TestMode::inTestEnvironment();
            if (!DiagnosticsSwitch::enabled($disabled, Env::get('DISABLE_DIAGNOSTICS'), $testEnvironment)) {
                return;
            }
            $diagnostics = new self(
                $queue,
                DiagnosticsAttributes::resource($projectId),
                DiagnosticsAttributes::record(),
                $clock ?? static fn(): float => hrtime(true) / 1e9,
                $wallMillis ?? static fn(): int => (int) (microtime(true) * 1000), // whole milliseconds, like Date.now()
            );
            self::$active = $diagnostics;
            $queue->attach($diagnostics);
            Log::setDiagnosticsSink($diagnostics->capture(...));
        } catch (\Throwable) {
            // diagnostics setup must never throw
        }
    }

    public static function enabled(): bool
    {
        return self::$active instanceof self;
    }

    /** Tests only: forgets the decision and removes the sink. */
    public static function reset(): void
    {
        self::$initialized = false;
        self::$warned = false;
        self::$active = null;
        Log::setDiagnosticsSink(null);
    }

    /** Posts the buffer once its oldest line is 2 s old (the TypeScript timer, checked on queue passes). */
    #[\Override]
    public function tick(): void
    {
        $firstAt = $this->firstAt;
        if ($firstAt !== null && ($this->clock)() - $firstAt >= self::BATCH_FLUSH_SECONDS) {
            $this->flush();
        }
    }

    /** Posts what is left; an empty buffer posts nothing (a signal drain and the shutdown function both finish). */
    #[\Override]
    public function finish(): void
    {
        $this->flush();
    }

    #[\Override]
    public function hasWork(): bool
    {
        return $this->buffer !== [];
    }

    /** The `Log` sink: buffers one line; the first line arms the queue's shutdown delivery. Never throws. */
    private function capture(string $line): void
    {
        try {
            $now = ($this->clock)();
            [$number, $text] = Severity::of($line);
            $millis = ($this->wallMillis)();
            if (count($this->buffer) >= self::MAX_BUFFER) {
                array_shift($this->buffer);
            }
            $this->buffer[] = [
                'timeUnixNano' => $millis === 0 ? '0' : $millis . '000000',
                'severityNumber' => $number,
                'severityText' => $text,
                'body' => ['stringValue' => $line],
                'attributes' => $this->recordAttributes,
            ];
            $this->firstAt ??= $now;
            if (!$this->armed) {
                $this->armed = true; // before arm(): its failure line comes back through capture()
                $this->queue->arm();
            }
        } catch (\Throwable) {
            // capture must never throw
        }
    }

    /** Posts the buffer as one request on the queue's side channel; nothing when it is empty. Never throws. */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        $records = $this->buffer;
        $this->buffer = [];
        $this->firstAt = null;

        try {
            $this->queue->channel()->post(
                new HttpRequest(DiagnosticsEndpoint::url(), ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . DiagnosticsEndpoint::token()], DiagnosticsPayload::encode($this->resource, $records, SdkVersion::get())),
                static function (): void {}, // the answer is ignored (TS)
            );
        } catch (\Throwable) {
            // the records are lost, like TS
        }
    }
}
