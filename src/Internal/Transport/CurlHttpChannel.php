<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/**
 * The second curl_multi handle of a queue: exporter and diagnostics requests, no retries (TypeScript sends each once),
 * connect and total timeouts (the request's, else the channel's), http/https only, no redirects. `pump()` never
 * blocks. PHP warnings are suppressed and every throwable is swallowed (D32; tests see them through `$onSwallowed`): a
 * failed request reaches only its callback. `CurlMulti` runs the transfers.
 *
 * At most 1 000 requests with at most 16 MiB of bodies wait; the oldest are dropped, with one line per pass. While one
 * of its PHP callbacks runs (`busy()`), a signal handler there (the SIGTERM drain) gets nothing pending and its pumps
 * and waits do nothing: libcurl refuses every multi call from inside a callback. Each callback marks the channel busy
 * in its first statement; only PHP 8.4+ can run a signal before that, at the callback's entry, and there the first
 * refused `curl_multi_exec()` marks the channel busy (`curlOptions()`).
 *
 * @internal
 */
final class CurlHttpChannel implements HttpChannel
{
    public const int MAX_CONCURRENT = 10;

    public const int MAX_WAITING = 1000;

    /** The bodies of the waiting requests, at most (16 MiB). */
    public const int MAX_WAITING_BYTES = 16_777_216;

    public const int MAX_BODY_BYTES = 65_536;

    /** Upper bound of one wait, so a waiting caller re-checks its state at least once a second. */
    private const float MAX_WAIT_SECONDS = 1.0;

    private const string NO_CURL = 'WARN: AgentCat cannot send telemetry exports or diagnostics: ext-curl functions are missing or disabled (%s)';

    private const string FULL = 'WARN: AgentCat telemetry export queue full, dropped the oldest %d request(s)';

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

    /** The body bytes of `$waiting`. */
    private int $waitingBytes = 0;

    /** Requests dropped from a full queue since the last pass; `pump()` logs them in one line. */
    private int $dropped = 0;

    /** Set by the write and header functions while they run. */
    private readonly CallbackFlag $inCallback;

    /**
     * @param (\Closure(string): bool)|null       $functionExists tests only: tells whether a curl function exists. Default `function_exists()`.
     * @param (\Closure(string, int): int)|null   $multiStatus    tests only: receives the function name and the CURLM code of each
     *                                                            `curl_multi_add_handle()` and `curl_multi_exec()` call, returns the code to act on
     * @param (\Closure(\Throwable): void)|null $onSwallowed    tests only: sees every throwable the channel swallows (D32); must not throw
     * @param (\Closure(string, CallbackFlag): void)|null $onCallback tests only: runs inside every write and header
     *                                                            callback (`write` or `header`), where a signal handler can
     *                                                            run too, with the busy flag: a test clears it to stand for
     *                                                            PHP 8.4+'s callback entry
     *
     * @throws \InvalidArgumentException for a timeout curl cannot take (`CurlSupport::timeoutMilliseconds()`)
     */
    public function __construct(
        float $connectTimeoutSeconds = CurlTransport::CONNECT_TIMEOUT_SECONDS,
        float $totalTimeoutSeconds = CurlTransport::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $functionExists = null,
        ?\Closure $multiStatus = null,
        private readonly ?\Closure $onSwallowed = null,
        private readonly ?\Closure $onCallback = null,
    ) {
        $this->connectTimeoutMs = CurlSupport::timeoutMilliseconds($connectTimeoutSeconds);
        $this->totalTimeoutMs = CurlSupport::timeoutMilliseconds($totalTimeoutSeconds);
        $this->curl = new CurlSupport(self::NO_CURL, $functionExists);
        $this->multi = new CurlMulti($multiStatus);
        $this->inCallback = new CallbackFlag();
    }

    #[\Override]
    public function post(HttpRequest $request, \Closure $onDone): void
    {
        if (!$this->curl->usable()) {
            return; // dropped: nothing can send it
        }
        $bytes = strlen($request->body);
        // The oldest go until this one fits; it is never dropped itself, even when its body alone is over the budget.
        while ($this->waiting !== [] && (count($this->waiting) >= self::MAX_WAITING || $this->waitingBytes + $bytes > self::MAX_WAITING_BYTES)) {
            $oldest = array_shift($this->waiting);
            $this->waitingBytes -= strlen($oldest['request']->body);
            ++$this->dropped;
        }
        $this->waiting[] = ['request' => $request, 'onDone' => $onDone];
        $this->waitingBytes += $bytes;
    }

    /** Also logs the requests dropped since the last pass, in one line. Does no curl work while `busy()`. */
    #[\Override]
    public function pump(): void
    {
        if (!$this->busy() && ($this->waiting !== [] || $this->multi->count() > 0)) { // else nothing posted (or nothing can be): no curl call
            $this->guarded(function (): void {
                $this->launch();
                if ($this->collect()) {
                    $this->launch(); // requests that callbacks posted (Sentry's sequence) start in this pass
                }
            });
        }
        if ($this->dropped > 0) {
            Log::write(sprintf(self::FULL, $this->dropped));
            $this->dropped = 0;
        }
    }

    /** 0 while `busy()`: inside its own callback nothing can progress, so a drain there has nothing to wait for. */
    #[\Override]
    public function pending(bool $includeBackground = true): int
    {
        if ($this->busy()) {
            return 0;
        }
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
        if ($this->multi->count() === 0 || $this->busy()) {
            return; // a waiting request starts at the next pump(); inside a callback nothing can progress
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

    /**
     * True while one of its PHP callbacks runs, inside `curl_multi_exec()`. An `exit()` in a signal handler there ends
     * the callback before it clears the flag; the flag is cleared once no `curl_multi_exec()` is on the stack (the
     * shutdown functions run after the stack unwound).
     */
    private function busy(): bool
    {
        if (!$this->inCallback->active) {
            return false;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if ($frame['function'] === 'curl_multi_exec') {
                return true;
            }
        }
        $this->inCallback->active = false;

        return false;
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
            $this->waitingBytes -= strlen($next['request']->body);
            $transfer = new HttpTransfer($next['onDone'], $next['request']->background);

            try {
                $error = $this->multi->open($this->curlOptions($next['request'], $transfer), $transfer);
            } catch (\Throwable $exception) {
                $error = ErrorText::of($exception); // a URL with a NUL byte (ValueError): this request fails, the others go on
            }
            if ($error !== null) {
                $this->finish($transfer->onDone, HttpResponse::failed($error));
            }
        }
    }

    /**
     * Runs the transfers, then runs the callback of every transfer that this run finished (or that a failed run
     * dropped). False when libcurl refused the run: the call came from inside one of its callbacks, and from now until
     * that callback ends the channel is `busy()`.
     */
    private function collect(): bool
    {
        $ran = $this->multi->run(
            function (HttpTransfer $transfer, \CurlHandle $handle, int $result): void {
                $this->finish($transfer->onDone, $result === CURLE_OK
                    ? new HttpResponse(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $transfer->reason, $transfer->body)
                    : HttpResponse::failed(CurlSupport::transferError($handle, $result)));
            },
            function (HttpTransfer $transfer, string $error): void {
                $this->finish($transfer->onDone, HttpResponse::failed($error));
            },
        );
        if (!$ran) {
            $this->inCallback->active = true; // the callback clears it when it ends
        }

        return $ran;
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
     * `$transfer` and the callback flag, so a finished handle frees the transfer and no handle holds the channel. The
     * request's timeouts win over the channel's (diagnostics: 250 ms and 1 s, D49).
     *
     * Each callback sets the flag as its first statement and clears it as its last one before `return`, with no call
     * and no jump after it. PHP runs a pending signal after a function call and at a jump (PHP 8.3, 8.4 and 8.5), and
     * PHP 8.4+ also at the entry of a callback that C calls. So a handler inside the callback sees the flag, except one
     * that PHP 8.4+ runs at the entry; for that one, the first `curl_multi_exec()` it attempts is refused, and the
     * refusal marks the channel busy (`collect()`). A `finally` would not do: leaving it jumps back to the `return`,
     * and a handler run there would see the flag cleared while libcurl is still in `curl_multi_exec()`. An `exit()` or
     * a throwable inside the callback leaves the flag set; `busy()` clears it once no `curl_multi_exec()` is on the
     * stack.
     *
     * @return array<int, mixed>
     *
     * @throws \InvalidArgumentException for a request timeout curl cannot take: that request fails
     */
    public function curlOptions(HttpRequest $request, HttpTransfer $transfer): array
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $inCallback = $this->inCallback;
        $onCallback = $this->onCallback;
        $capture = [
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $data) use ($transfer, $inCallback, $onCallback): int {
                $inCallback->active = true;
                if ($onCallback instanceof \Closure) {
                    $onCallback('write', $inCallback);
                }
                // Up to MAX_BODY_BYTES are kept; the rest is read and dropped.
                $transfer->body .= substr($data, 0, self::MAX_BODY_BYTES - strlen($transfer->body));
                $taken = strlen($data); // a shorter return value would abort the transfer
                $inCallback->active = false; // last: see curlOptions()

                return $taken;
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use ($transfer, $inCallback, $onCallback): int {
                $inCallback->active = true;
                if ($onCallback instanceof \Closure) {
                    $onCallback('header', $inCallback);
                }
                if (preg_match(self::STATUS_LINE, $line, $match) === 1) {
                    $transfer->reason = $match[1] ?? '';
                }
                $taken = strlen($line);
                $inCallback->active = false; // last: see curlOptions()

                return $taken;
            },
        ];

        $connectTimeoutMs = $request->connectTimeoutSeconds === null ? $this->connectTimeoutMs : CurlSupport::timeoutMilliseconds($request->connectTimeoutSeconds);
        $totalTimeoutMs = $request->totalTimeoutSeconds === null ? $this->totalTimeoutMs : CurlSupport::timeoutMilliseconds($request->totalTimeoutSeconds);

        return CurlSupport::postOptions($capture, $request->url, $request->body, $headers, $connectTimeoutMs, $totalTimeoutMs);
    }
}
