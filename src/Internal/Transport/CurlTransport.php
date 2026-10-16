<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;

/**
 * One `POST` per event over ext-curl: `curl_multi` transfers, at most five at once, with TypeScript's retry
 * policy scheduled by time (`pump()` never sleeps) and connect/total timeouts. Nothing reaches STDOUT/STDERR.
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

    private readonly \CurlMultiHandle $multi;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var list<array{delivery: Delivery, attempt: int}> attempts waiting for a free transfer slot */
    private array $ready = [];

    /** @var list<array{delivery: Delivery, attempt: int, due: float}> retries waiting for their time */
    private array $scheduled = [];

    /**
     * Transfers in `$multi`, keyed by `spl_object_id()` of their handle; `$multi` keeps each handle alive until removed.
     *
     * @var array<int, array{delivery: Delivery, attempt: int}>
     */
    private array $active = [];

    /**
     * @param (\Closure(): float)|null $clock monotonic seconds (tests); must not throw. Default `hrtime(true) / 1e9`.
     */
    public function __construct(
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly float $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
        private readonly float $totalTimeoutSeconds = self::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $clock = null,
    ) {
        $this->multi = curl_multi_init();
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    public static function userAgent(): string
    {
        return 'agentcat-php/' . SdkVersion::get() . ' php/' . PHP_VERSION;
    }

    #[\Override]
    public function start(Delivery $delivery): void
    {
        $this->guarded(function () use ($delivery): void {
            $this->ready[] = ['delivery' => $delivery, 'attempt' => 1];
            $this->launch();
        });
    }

    #[\Override]
    public function pump(): void
    {
        $this->guarded(function (): void {
            $this->collect();
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
        });
    }

    #[\Override]
    public function waitForActivity(float $deadline): void
    {
        $this->guarded(function () use ($deadline): void {
            $now = $this->now();
            $until = $deadline;
            foreach ($this->scheduled as $retry) {
                $until = min($until, $retry['due']);
            }
            // A passed deadline or an overdue retry gives 0: no wait.
            $timeout = max(0.0, min($until - $now, self::MAX_WAIT_SECONDS));
            if ($this->active !== []) {
                if (curl_multi_select($this->multi, $timeout) === -1) {
                    usleep(1000);
                }

                return;
            }
            usleep((int) ($timeout * 1e6));
        });
    }

    #[\Override]
    public function drain(float $deadline): bool
    {
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
        }
    }

    #[\Override]
    public function send(Delivery $delivery, float $deadline = INF): bool
    {
        $this->start($delivery);

        return $this->drain($deadline);
    }

    #[\Override]
    public function inFlight(): int
    {
        return count($this->ready) + count($this->active) + count($this->scheduled);
    }

    #[\Override]
    public function now(): float
    {
        return ($this->clock)();
    }

    /** @return array<int, mixed> */
    public function curlOptions(Delivery $delivery): array
    {
        return [
            CURLOPT_URL => $delivery->url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $delivery->body,
            // A blank `Expect:` stops libcurl from waiting for `100 Continue` on large bodies.
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($this->totalTimeoutSeconds * 1000),
        ];
    }

    /**
     * Runs transport work: PHP warnings are suppressed (a framework handler may turn them into exceptions) and
     * any throwable is logged, never thrown (D32).
     *
     * @param \Closure(): void $work
     */
    private function guarded(\Closure $work): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $work();
        } catch (\Throwable $error) {
            Log::write(self::GAVE_UP . ErrorText::of($error));
        } finally {
            restore_error_handler();
        }
    }

    private function launch(): void
    {
        while (count($this->active) < self::MAX_CONCURRENT && $this->ready !== []) {
            $next = array_shift($this->ready);
            $beforeAttempt = $next['delivery']->beforeAttempt;
            if ($beforeAttempt !== null) {
                try {
                    $beforeAttempt();
                } catch (\Throwable) {
                    // Exporter errors are logged by the queue's closure; they never stop the delivery.
                }
            }
            $handle = curl_init();
            if ($handle === false) {
                $this->failed($next['delivery'], $next['attempt'], 'cURL error: curl_init() failed');

                continue;
            }
            curl_setopt_array($handle, $this->curlOptions($next['delivery']));
            curl_multi_add_handle($this->multi, $handle);
            $this->active[spl_object_id($handle)] = $next;
        }
        if ($this->active !== []) {
            curl_multi_exec($this->multi, $running);
        }
    }

    private function collect(): void
    {
        curl_multi_exec($this->multi, $running);
        while (true) {
            /** @var array{msg: int, result: int, handle: \CurlHandle}|false $info the documented shape */
            $info = curl_multi_info_read($this->multi);
            if ($info === false) {
                return;
            }
            $id = spl_object_id($info['handle']);
            $entry = $this->active[$id] ?? null;
            unset($this->active[$id]);
            curl_multi_remove_handle($this->multi, $info['handle']);
            if ($entry !== null) {
                $this->finished($entry['delivery'], $entry['attempt'], $info['handle'], $info['result']);
            }
        }
    }

    private function finished(Delivery $delivery, int $attempt, \CurlHandle $handle, int $result): void
    {
        if ($result !== CURLE_OK) {
            $text = curl_error($handle);
            $this->failed($delivery, $attempt, sprintf('cURL error %d: %s', $result, $text !== '' ? $text : curl_strerror($result)));

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
