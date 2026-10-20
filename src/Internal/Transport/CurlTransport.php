<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\RepeatedLines;
use AgentCat\Internal\Support\Clock;
use AgentCat\Internal\Support\ErrorText;

/**
 * One `POST` per event over ext-curl: `curl_multi` transfers, at most five at once, with TypeScript's retry
 * policy scheduled by time (`pump()` never sleeps) and connect/total timeouts. Nothing reaches STDOUT/STDERR.
 * The multi handle starts with the first delivery. When a curl function it calls is missing or disabled, every
 * delivery is dropped, and one line per process says so (`CurlSupport`, D32).
 *
 * @internal
 */
final class CurlTransport implements Transport
{
    public const float CONNECT_TIMEOUT_SECONDS = 2.0;

    public const float TOTAL_TIMEOUT_SECONDS = 10.0;

    public const int MAX_CONCURRENT = 5;

    private const string GAVE_UP = 'Failed to deliver AgentCat event after retries: ';

    /** Upper bound of one wait, so a waiting caller re-checks its state at least once a second. */
    private const float MAX_WAIT_SECONDS = 1.0;

    /** The ext-curl check, made at the first delivery. */
    private readonly CurlSupport $curl;

    /** @var CurlMulti<array{delivery: Delivery, attempt: int}> the attempts in transfer */
    private readonly CurlMulti $multi;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    private readonly int $connectTimeoutMs;

    private readonly int $totalTimeoutMs;

    /** @var list<array{delivery: Delivery, attempt: int}> attempts waiting for a free transfer slot */
    private array $ready = [];

    /** @var list<array{delivery: Delivery, attempt: int, due: float}> retries waiting for their time */
    private array $scheduled = [];

    /**
     * @param (\Closure(): float)|null          $clock          monotonic seconds (tests); must not throw. Default `Clock::monotonic()`.
     * @param (\Closure(string, int): int)|null $multiStatus    tests only: receives the function name and the CURLM code of each
     *                                                          `curl_multi_add_handle()` and `curl_multi_exec()` call, returns the code to act on
     * @param (\Closure(string): bool)|null     $functionExists tests only: tells whether a curl function exists. Default `function_exists()`.
     *
     * @throws \InvalidArgumentException for a timeout curl cannot take (`CurlSupport::timeoutMilliseconds()`)
     */
    public function __construct(
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        float $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
        float $totalTimeoutSeconds = self::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $clock = null,
        ?\Closure $multiStatus = null,
        ?\Closure $functionExists = null,
    ) {
        $this->connectTimeoutMs = CurlSupport::timeoutMilliseconds($connectTimeoutSeconds);
        $this->totalTimeoutMs = CurlSupport::timeoutMilliseconds($totalTimeoutSeconds);
        $this->clock = $clock ?? Clock::monotonic();
        $this->curl = new CurlSupport($functionExists);
        $this->multi = new CurlMulti($multiStatus);
    }

    /** `agentcat-php/<version> php/<version>`, also the User-Agent of the exporters and diagnostics. */
    public static function userAgent(): string
    {
        return CurlSupport::userAgent();
    }

    #[\Override]
    public function start(Delivery $delivery): void
    {
        if (!$this->curl->usable()) {
            return; // dropped: nothing can send it
        }
        $this->guarded(function () use ($delivery): void {
            $this->ready[] = ['delivery' => $delivery, 'attempt' => 1];
            $this->launch();
            $this->collect();
        });
    }

    #[\Override]
    public function pump(): void
    {
        if (!$this->curl->confirmed()) {
            return; // no delivery started yet, or none can be
        }
        // Collecting comes last: every curl_multi_exec() is followed by a read of the transfers it finished, so
        // a finished transfer never waits in libcurl while waitForActivity() blocks on other sockets.
        $this->guarded(function (): void {
            $now = $this->now();
            $due = [];
            $waiting = [];
            foreach ($this->scheduled as $retry) {
                if ($retry['due'] <= $now) {
                    $due[] = $retry;
                } else {
                    $waiting[] = $retry;
                }
            }
            $this->scheduled = $waiting;
            usort($due, static fn(array $a, array $b): int => $a['due'] <=> $b['due']);
            foreach ($due as $retry) {
                $this->ready[] = ['delivery' => $retry['delivery'], 'attempt' => $retry['attempt']];
            }
            $this->launch();
            $this->collect();
        });
    }

    #[\Override]
    public function waitForActivity(float $deadline): void
    {
        $this->guarded(function () use ($deadline): void {
            if ($this->ready !== [] && $this->multi->count() < self::MAX_CONCURRENT) {
                return; // a waiting attempt can start now (a slot freed up after the last launch)
            }
            $now = $this->now();
            $until = $deadline;
            foreach ($this->scheduled as $retry) {
                $until = min($until, $retry['due']);
            }
            // A passed deadline or an overdue retry gives 0: no wait.
            $timeout = CurlSupport::waitSeconds($until - $now, self::MAX_WAIT_SECONDS);
            if ($this->multi->count() > 0) {
                $this->multi->select($timeout);

                return;
            }
            usleep((int) ($timeout * 1e6));
        });
    }

    #[\Override]
    public function drain(float $deadline): bool
    {
        RepeatedLines::begin(); // a failure of every pass is logged once

        try {
            while (true) {
                $this->pump();
                if ($this->inFlight() === 0) {
                    return true;
                }
                if ($this->now() >= $deadline) {
                    return false;
                }
                $this->waitForActivity($deadline);
            }
        } catch (\Throwable $error) {
            Log::write(self::GAVE_UP . ErrorText::of($error));

            return false;
        } finally {
            RepeatedLines::end();
        }
    }

    #[\Override]
    public function send(Delivery $delivery, float $deadline = INF): bool
    {
        RepeatedLines::begin(); // the first step in start() is a pass of the same delivery

        try {
            $this->start($delivery);

            return $this->drain($deadline);
        } finally {
            RepeatedLines::end();
        }
    }

    #[\Override]
    public function inFlight(): int
    {
        return count($this->ready) + $this->multi->count() + count($this->scheduled);
    }

    #[\Override]
    public function now(): float
    {
        return ($this->clock)();
    }

    /** @return array<int, mixed> */
    public function curlOptions(Delivery $delivery): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        return CurlSupport::postOptions([CURLOPT_RETURNTRANSFER => true], $delivery->url, $delivery->body, $headers, $this->connectTimeoutMs, $this->totalTimeoutMs);
    }

    /**
     * Runs transport work: PHP warnings are suppressed (a framework handler may turn them into exceptions) and
     * any throwable is logged, never thrown (D32); inside a delivery, a repeated one once (`RepeatedLines`).
     *
     * @param \Closure(): void $work
     */
    private function guarded(\Closure $work): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $work();
        } catch (\Throwable $error) {
            RepeatedLines::write(self::GAVE_UP . ErrorText::of($error));
        } finally {
            restore_error_handler();
        }
    }

    /** Starts waiting attempts while slots are free; the next collect() runs their first curl_multi_exec(). */
    private function launch(): void
    {
        while ($this->multi->count() < self::MAX_CONCURRENT && $this->ready !== []) {
            $next = array_shift($this->ready);
            $beforeAttempt = $next['delivery']->beforeAttempt;
            if ($beforeAttempt !== null) {
                try {
                    $beforeAttempt();
                } catch (\Throwable) {
                    // Exporter errors are logged by the queue's closure; they never stop the delivery.
                }
            }
            $error = $this->multi->open($this->curlOptions($next['delivery']), $next);
            if ($error !== null) {
                $this->failed($next['delivery'], $next['attempt'], $error);
            }
        }
    }

    /** Runs the transfers, then reads every transfer that this run finished (or that a failed run dropped). */
    private function collect(): void
    {
        $this->multi->run(
            /** @param array{delivery: Delivery, attempt: int} $attempt */
            function (array $attempt, \CurlHandle $handle, int $result): void {
                $this->finished($attempt['delivery'], $attempt['attempt'], $handle, $result);
            },
            /** @param array{delivery: Delivery, attempt: int} $attempt */
            function (array $attempt, string $error): void {
                $this->failed($attempt['delivery'], $attempt['attempt'], $error);
            },
        );
    }

    private function finished(Delivery $delivery, int $attempt, \CurlHandle $handle, int $result): void
    {
        if ($result !== CURLE_OK) {
            $this->failed($delivery, $attempt, CurlSupport::transferError($handle, $result));

            return;
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($status >= 200 && $status < 300) {
            Log::write('Successfully sent event ' . $delivery->eventId . ' | ' . $delivery->summary);

            return;
        }
        $this->failed($delivery, $attempt, 'HTTP ' . $status);
    }

    private function failed(Delivery $delivery, int $attempt, string $error): void
    {
        Log::write(sprintf('Failed to send event %s, retrying... [Error: %s]', $delivery->eventId, $error));
        $delay = $this->retryPolicy->delayAfterAttempt($attempt);
        if ($delay === null) {
            Log::write(self::GAVE_UP . $error);

            return;
        }
        $this->scheduled[] = ['delivery' => $delivery, 'attempt' => $attempt + 1, 'due' => $this->now() + $delay];
    }
}
