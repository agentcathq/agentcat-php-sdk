<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/**
 * The second curl_multi handle of a queue: exporter and diagnostics requests, no retries (TypeScript sends each once),
 * connect and total timeouts, http/https only, no redirects. `pump()` never blocks. PHP warnings are suppressed and
 * every throwable is swallowed (D32): a failed request reaches only its callback.
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

    /** Null until the first transfer. */
    private ?\CurlMultiHandle $multi = null;

    /** The ext-curl check, made at the first post. */
    private readonly CurlSupport $curl;

    /** @var \Closure(string, int): int */
    private readonly \Closure $multiStatus;

    /** @var list<array{request: HttpRequest, onDone: \Closure(HttpResponse): void}> */
    private array $waiting = [];

    /** @var array<int, array{handle: \CurlHandle, onDone: \Closure(HttpResponse): void, reason: string, body: string}> transfers in `$multi`, keyed by `spl_object_id($handle)` */
    private array $active = [];

    /**
     * @param (\Closure(string): bool)|null     $functionExists tests only: tells whether a curl function exists. Default `function_exists()`.
     * @param (\Closure(string, int): int)|null $multiStatus    tests only: receives the function name and the CURLM code of each
     *                                                          `curl_multi_add_handle()` and `curl_multi_exec()` call, returns the code to act on
     */
    public function __construct(
        private readonly float $connectTimeoutSeconds = CurlTransport::CONNECT_TIMEOUT_SECONDS,
        private readonly float $totalTimeoutSeconds = CurlTransport::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $functionExists = null,
        ?\Closure $multiStatus = null,
    ) {
        $this->curl = new CurlSupport(self::NO_CURL, $functionExists);
        $this->multiStatus = $multiStatus ?? static fn(string $function, int $code): int => $code;
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
        if ($this->waiting === [] && $this->active === []) {
            return; // nothing posted (or nothing can be): no curl call
        }
        $this->guarded(function (): void {
            $this->launch();
            $this->collect();
            $this->launch(); // requests that callbacks posted (Sentry's sequence) start in this pass
        });
    }

    #[\Override]
    public function pending(): int
    {
        return count($this->waiting) + count($this->active);
    }

    #[\Override]
    public function waitForActivity(float $seconds): void
    {
        if ($this->active === []) {
            return; // a waiting request starts at the next pump()
        }
        $timeout = is_nan($seconds) ? 0.0 : max(0.0, min($seconds, self::MAX_WAIT_SECONDS));
        $this->guarded(function () use ($timeout): void {
            if (curl_multi_select($this->multi(), $timeout) === -1) {
                usleep(1000);
            }
        });
    }

    /** Transfers in the multi handle (tests). */
    public function inTransfer(): int
    {
        return count($this->active);
    }

    private function multi(): \CurlMultiHandle
    {
        return $this->multi ??= curl_multi_init();
    }

    /** @param \Closure(): void $work */
    private function guarded(\Closure $work): void
    {
        set_error_handler(static fn(): bool => true);

        try {
            $work();
        } catch (\Throwable) {
            // a side request must never reach the host (D32)
        }
        restore_error_handler();
    }

    /** Starts waiting requests while slots are free; the next collect() runs their first curl_multi_exec(). */
    private function launch(): void
    {
        while (count($this->active) < self::MAX_CONCURRENT && $this->waiting !== []) {
            $next = array_shift($this->waiting);
            $handle = curl_init();
            if ($handle === false) {
                $this->finish($next['onDone'], HttpResponse::failed('cURL error: curl_init() failed'));

                continue;
            }
            $id = spl_object_id($handle);

            try {
                curl_setopt_array($handle, $this->options($next['request'], $id));
            } catch (\Throwable $error) {
                // a URL with a NUL byte (ValueError): the request fails, the others go on
                $this->finish($next['onDone'], HttpResponse::failed(ErrorText::of($error)));

                continue;
            }
            // Recorded before the add: a signal drain that runs right after the add must see this transfer.
            $this->active[$id] = ['handle' => $handle, 'onDone' => $next['onDone'], 'reason' => '', 'body' => ''];
            $code = ($this->multiStatus)('curl_multi_add_handle', curl_multi_add_handle($this->multi(), $handle));
            if ($code !== CURLM_OK) {
                unset($this->active[$id]);
                // PHP keeps the handle in its list of the multi handle even when libcurl refused it.
                curl_multi_remove_handle($this->multi(), $handle);
                $this->finish($next['onDone'], HttpResponse::failed(CurlSupport::multiError($code)));
            }
        }
    }

    /** Runs the transfers, then reads every transfer that this run finished and runs its callback. */
    private function collect(): void
    {
        $code = ($this->multiStatus)('curl_multi_exec', curl_multi_exec($this->multi(), $running));
        if ($code !== CURLM_OK) {
            // The multi handle cannot run its transfers: fail them, so that no flush waits for them forever.
            $active = $this->active;
            $this->active = [];
            foreach ($active as $entry) {
                curl_multi_remove_handle($this->multi(), $entry['handle']);
                $this->finish($entry['onDone'], HttpResponse::failed(CurlSupport::multiError($code)));
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
                $this->finish($entry['onDone'], $info['result'] === CURLE_OK
                    ? new HttpResponse(curl_getinfo($info['handle'], CURLINFO_RESPONSE_CODE), $entry['reason'], $entry['body'])
                    : HttpResponse::failed(CurlSupport::transferError($info['handle'], $info['result'])));
            }
        }
    }

    /** @param \Closure(HttpResponse): void $onDone */
    private function finish(\Closure $onDone, HttpResponse $response): void
    {
        try {
            $onDone($response);
        } catch (\Throwable) {
            // our callbacks log and never throw; a broken one must not stop the others
        }
    }

    /** @return array<int, mixed> */
    private function options(HttpRequest $request, int $id): array
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return CurlSupport::postOptions($request->url, $request->body, $headers, $this->connectTimeoutSeconds, $this->totalTimeoutSeconds) + [
            CURLOPT_HEADERFUNCTION => function (\CurlHandle $handle, string $line) use ($id): int {
                if (isset($this->active[$id]) && preg_match(self::STATUS_LINE, $line, $match) === 1) {
                    $this->active[$id]['reason'] = $match[1] ?? '';
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $handle, string $data) use ($id): int {
                if (isset($this->active[$id])) {
                    // Up to MAX_BODY_BYTES are kept; the rest is read and dropped.
                    $this->active[$id]['body'] .= substr($data, 0, self::MAX_BODY_BYTES - strlen($this->active[$id]['body']));
                }

                return strlen($data); // a shorter return value would abort the transfer
            },
        ];
    }
}
