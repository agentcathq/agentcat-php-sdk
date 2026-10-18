<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/**
 * The second curl_multi handle of a queue: exporter and diagnostics requests, no retries (TypeScript sends each once),
 * connect and total timeouts, http/https only, no redirects. `pump()` never blocks. PHP warnings are suppressed and
 * every throwable is swallowed (D32; tests see them through `$onSwallowed`): a failed request reaches only its
 * callback. `CurlMulti` runs the transfers.
 *
 * @internal
 */
final class CurlHttpChannel implements HttpChannel
{
    public const int MAX_CONCURRENT = 10;

    public const int MAX_WAITING = 1000;

    public const int MAX_BODY_BYTES = 65_536;

    /** Upper bound of one wait, so a waiting caller re-checks its state at least once a second. */
    private const float MAX_WAIT_SECONDS = 1.0;

    private const string NO_CURL = 'WARN: AgentCat cannot send telemetry exports or diagnostics: ext-curl functions are missing or disabled (%s)';

    private const string FULL = 'WARN: AgentCat telemetry export queue full, dropping the oldest request';

    /** The reason phrase of a status line; the last status line of a response wins. */
    private const string STATUS_LINE = '~\AHTTP/\S+\s+\d{3}(?:[ \t]+([^\r\n]*))?~';

    /** The ext-curl check, made at the first post. */
    private readonly CurlSupport $curl;

    /** @var CurlMulti<HttpTransfer> the requests in transfer */
    private readonly CurlMulti $multi;

    private readonly int $connectTimeoutMs;

    private readonly int $totalTimeoutMs;

    /** @var list<array{request: HttpRequest, onDone: \Closure(HttpResponse): void}> */
    private array $waiting = [];

    /**
     * @param (\Closure(string): bool)|null       $functionExists tests only: tells whether a curl function exists. Default `function_exists()`.
     * @param (\Closure(string, int): int)|null   $multiStatus    tests only: receives the function name and the CURLM code of each
     *                                                            `curl_multi_add_handle()` and `curl_multi_exec()` call, returns the code to act on
     * @param (\Closure(\Throwable): void)|null $onSwallowed    tests only: sees every throwable the channel swallows (D32); must not throw
     *
     * @throws \InvalidArgumentException for a timeout curl cannot take (`CurlSupport::timeoutMilliseconds()`)
     */
    public function __construct(
        float $connectTimeoutSeconds = CurlTransport::CONNECT_TIMEOUT_SECONDS,
        float $totalTimeoutSeconds = CurlTransport::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $functionExists = null,
        ?\Closure $multiStatus = null,
        private readonly ?\Closure $onSwallowed = null,
    ) {
        $this->connectTimeoutMs = CurlSupport::timeoutMilliseconds($connectTimeoutSeconds);
        $this->totalTimeoutMs = CurlSupport::timeoutMilliseconds($totalTimeoutSeconds);
        $this->curl = new CurlSupport(self::NO_CURL, $functionExists);
        $this->multi = new CurlMulti($multiStatus);
    }

    #[\Override]
    public function post(HttpRequest $request, \Closure $onDone): void
    {
        if (!$this->curl->usable()) {
            return; // dropped: nothing can send it
        }
        if (count($this->waiting) >= self::MAX_WAITING) {
            array_shift($this->waiting);
            Log::write(self::FULL);
        }
        $this->waiting[] = ['request' => $request, 'onDone' => $onDone];
    }

    #[\Override]
    public function pump(): void
    {
        if ($this->waiting === [] && $this->multi->count() === 0) {
            return; // nothing posted (or nothing can be): no curl call
        }
        $this->guarded(function (): void {
            $this->launch();
            $this->collect();
            $this->launch(); // requests that callbacks posted (Sentry's sequence) start in this pass
        });
    }

    #[\Override]
    public function pending(bool $includeBackground = true): int
    {
        if ($includeBackground) {
            return count($this->waiting) + $this->multi->count();
        }
        $pending = 0;
        foreach ($this->waiting as $waiting) {
            $pending += $waiting['request']->background ? 0 : 1;
        }
        foreach ($this->multi->payloads() as $transfer) {
            $pending += $transfer->background ? 0 : 1;
        }

        return $pending;
    }

    #[\Override]
    public function waitForActivity(float $seconds): void
    {
        if ($this->multi->count() === 0) {
            return; // a waiting request starts at the next pump()
        }
        $timeout = CurlSupport::waitSeconds($seconds, self::MAX_WAIT_SECONDS);
        $this->guarded(function () use ($timeout): void {
            $this->multi->select($timeout);
        });
    }

    /** Transfers in the multi handle (tests). */
    public function inTransfer(): int
    {
        return $this->multi->count();
    }

    /** @param \Closure(): void $work */
    private function guarded(\Closure $work): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $work();
        } catch (\Throwable $error) {
            $this->swallowed($error); // a side request must never reach the host (D32)
        }
        restore_error_handler();
    }

    private function swallowed(\Throwable $error): void
    {
        if ($this->onSwallowed instanceof \Closure) {
            ($this->onSwallowed)($error);
        }
    }

    /** Starts waiting requests while slots are free; the next collect() runs their first curl_multi_exec(). */
    private function launch(): void
    {
        while ($this->multi->count() < self::MAX_CONCURRENT && $this->waiting !== []) {
            $next = array_shift($this->waiting);
            $transfer = new HttpTransfer($next['onDone'], $next['request']->background);

            try {
                $error = $this->multi->open($this->options($next['request'], $transfer), $transfer);
            } catch (\Throwable $exception) {
                $error = ErrorText::of($exception); // a URL with a NUL byte (ValueError): this request fails, the others go on
            }
            if ($error !== null) {
                $this->finish($transfer->onDone, HttpResponse::failed($error));
            }
        }
    }

    /** Runs the transfers, then runs the callback of every transfer that this run finished (or that a failed run dropped). */
    private function collect(): void
    {
        $this->multi->run(
            function (HttpTransfer $transfer, \CurlHandle $handle, int $result): void {
                $this->finish($transfer->onDone, $result === CURLE_OK
                    ? new HttpResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $transfer->reason, $transfer->body)
                    : HttpResponse::failed(CurlSupport::transferError($handle, $result)));
            },
            function (HttpTransfer $transfer, string $error): void {
                $this->finish($transfer->onDone, HttpResponse::failed($error));
            },
        );
    }

    /** @param \Closure(HttpResponse): void $onDone */
    private function finish(\Closure $onDone, HttpResponse $response): void
    {
        try {
            $onDone($response);
        } catch (\Throwable $error) {
            $this->swallowed($error); // our callbacks log and never throw; a broken one must not stop the others
        }
    }

    /**
     * The options of `$request`: the write and header functions first (`CurlSupport::postOptions()`). They hold only
     * `$transfer`, so a finished handle frees it and no handle holds the channel.
     *
     * @return array<int, mixed>
     */
    private function options(HttpRequest $request, HttpTransfer $transfer): array
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $capture = [
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $data) use ($transfer): int {
                // Up to MAX_BODY_BYTES are kept; the rest is read and dropped.
                $transfer->body .= substr($data, 0, self::MAX_BODY_BYTES - strlen($transfer->body));

                return strlen($data); // a shorter return value would abort the transfer
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use ($transfer): int {
                if (preg_match(self::STATUS_LINE, $line, $match) === 1) {
                    $transfer->reason = $match[1] ?? '';
                }

                return strlen($line);
            },
        ];

        return CurlSupport::postOptions($capture, $request->url, $request->body, $headers, $this->connectTimeoutMs, $this->totalTimeoutMs);
    }
}
