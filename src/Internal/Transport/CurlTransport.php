<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;

/**
 * One `POST` per event over ext-curl: `curl_multi` transfers, at most five at once, with TypeScript's retry
 * policy scheduled by time (`pump()` never sleeps) and connect/total timeouts. Nothing reaches STDOUT/STDERR.
 * The multi handle starts with the first delivery. When a curl function it calls is missing or disabled, every
 * delivery is dropped and one line is logged (D32).
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

    private const string NO_CURL = 'WARN: AgentCat cannot send events: ext-curl functions are missing or disabled (%s)';

    /** Every curl function this class calls. */
    private const array FUNCTIONS = [
        'curl_init', 'curl_setopt_array', 'curl_error', 'curl_strerror', 'curl_getinfo', 'curl_multi_init',
        'curl_multi_add_handle', 'curl_multi_remove_handle', 'curl_multi_exec', 'curl_multi_info_read',
        'curl_multi_select', 'curl_multi_strerror',
    ];

    /** Null until the first transfer. */
    private ?\CurlMultiHandle $multi = null;

    /** Whether every function in FUNCTIONS exists; null until the first delivery checked it. */
    private ?bool $usable = null;

    /** @var \Closure(string): bool */
    private readonly \Closure $functionExists;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(string, int): int */
    private readonly \Closure $multiStatus;

    /** @var list<array{delivery: Delivery, attempt: int}> attempts waiting for a free transfer slot */
    private array $ready = [];

    /** @var list<array{delivery: Delivery, attempt: int, due: float}> retries waiting for their time */
    private array $scheduled = [];

    /** @var array<int, array{handle: \CurlHandle, delivery: Delivery, attempt: int}> transfers in `$multi`, keyed by `spl_object_id($handle)` */
    private array $active = [];

    /**
     * @param (\Closure(): float)|null          $clock          monotonic seconds (tests); must not throw. Default `hrtime(true) / 1e9`.
     * @param (\Closure(string, int): int)|null $multiStatus    tests only: receives the function name and the CURLM code of each
     *                                                          `curl_multi_add_handle()` and `curl_multi_exec()` call, returns the code to act on
     * @param (\Closure(string): bool)|null     $functionExists tests only: tells whether a curl function exists. Default `function_exists()`.
     */
    public function __construct(
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly float $connectTimeoutSeconds = self::CONNECT_TIMEOUT_SECONDS,
        private readonly float $totalTimeoutSeconds = self::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $clock = null,
        ?\Closure $multiStatus = null,
        ?\Closure $functionExists = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
        $this->multiStatus = $multiStatus ?? static fn(string $function, int $code): int => $code;
        $this->functionExists = $functionExists ?? function_exists(...);
    }

    public static function userAgent(): string
    {
        return 'agentcat-php/' . SdkVersion::get() . ' php/' . PHP_VERSION;
    }

    #[\Override]
    public function start(Delivery $delivery): void
    {
        if (!$this->usable()) {
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
        if ($this->usable !== true) {
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
            if ($this->ready !== [] && count($this->active) < self::MAX_CONCURRENT) {
                return; // a waiting attempt can start now (a slot freed up after the last launch)
            }
            $now = $this->now();
            $until = $deadline;
            foreach ($this->scheduled as $retry) {
                $until = min($until, $retry['due']);
            }
            // A passed deadline or an overdue retry gives 0: no wait.
            $timeout = max(0.0, min($until - $now, self::MAX_WAIT_SECONDS));
            if ($this->active !== []) {
                if (curl_multi_select($this->multi(), $timeout) === -1) {
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

    /** Whether every curl function this class calls exists. The first false answer logs one line. */
    private function usable(): bool
    {
        if ($this->usable === null) {
            $missing = array_filter(self::FUNCTIONS, fn(string $function): bool => !($this->functionExists)($function));
            $this->usable = $missing === [];
            if (!$this->usable) {
                Log::write(sprintf(self::NO_CURL, implode(', ', $missing)));
            }
        }

        return $this->usable;
    }

    private function multi(): \CurlMultiHandle
    {
        return $this->multi ??= curl_multi_init();
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

    /** Starts waiting attempts while slots are free; the next collect() runs their first curl_multi_exec(). */
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
            $code = ($this->multiStatus)('curl_multi_add_handle', curl_multi_add_handle($this->multi(), $handle));
            if ($code !== CURLM_OK) {
                // PHP keeps the handle in its list of the multi handle even when libcurl refused it.
                curl_multi_remove_handle($this->multi(), $handle);
                $this->failed($next['delivery'], $next['attempt'], $this->multiError($code));

                continue;
            }
            $this->active[spl_object_id($handle)] = ['handle' => $handle] + $next;
        }
    }

    /** Runs the transfers, then reads every transfer that this run finished. */
    private function collect(): void
    {
        $code = ($this->multiStatus)('curl_multi_exec', curl_multi_exec($this->multi(), $running));
        if ($code !== CURLM_OK) {
            // The multi handle cannot run its transfers: fail them, so that nothing waits for them forever.
            $active = $this->active;
            $this->active = [];
            foreach ($active as $entry) {
                curl_multi_remove_handle($this->multi(), $entry['handle']);
                $this->failed($entry['delivery'], $entry['attempt'], $this->multiError($code));
            }
        }
        while (true) {
            /** @var array{msg: int, result: int, handle: \CurlHandle}|false $info the documented shape */
            $info = curl_multi_info_read($this->multi());
            if ($info === false) {
                return;
            }
            $id = spl_object_id($info['handle']);
            $entry = $this->active[$id] ?? null;
            unset($this->active[$id]);
            curl_multi_remove_handle($this->multi(), $info['handle']);
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

    private function multiError(int $code): string
    {
        return sprintf('cURL multi error %d: %s', $code, curl_multi_strerror($code));
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
