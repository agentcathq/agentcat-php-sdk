<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;

/**
 * What `CurlTransport` and `CurlHttpChannel` share about ext-curl: the check that every curl function they call
 * exists (a PHP without ext-curl, or with some of its functions in `disable_functions`), made once by each and logged
 * once per process (D32), the User-Agent, the timeouts and the options of a `POST`, the bound of one wait, and the texts of a failed
 * transfer and of a failed multi handle. `CurlMulti` runs the transfers.
 *
 * @internal
 */
final class CurlSupport
{
    /** The longest curl timeout: 2^31 - 1 ms, what a 32-bit C `long` (curl's option type on Windows) carries. */
    public const float MAX_TIMEOUT_SECONDS = 2_147_483.647;

    /** The line of a PHP that lacks a function of FUNCTIONS; `%s` lists them. Both users need every one of them. */
    private const string MISSING = 'WARN: AgentCat cannot send events, telemetry exports or diagnostics: ext-curl functions are missing or disabled (%s)';

    /** Every curl function `CurlTransport` and `CurlHttpChannel` call. */
    private const array FUNCTIONS = [
        'curl_init', 'curl_setopt_array', 'curl_error', 'curl_strerror', 'curl_getinfo', 'curl_multi_init',
        'curl_multi_add_handle', 'curl_multi_remove_handle', 'curl_multi_exec', 'curl_multi_info_read',
        'curl_multi_select', 'curl_multi_strerror',
    ];

    /** Whether every function in FUNCTIONS exists; null until `usable()` checked it. */
    private ?bool $usable = null;

    /** Whether a check of this process logged MISSING: the event transport and the side channel share one line. */
    private static bool $reported = false;

    /** @var \Closure(string): bool */
    private readonly \Closure $functionExists;

    /** @param (\Closure(string): bool)|null $functionExists tests only: tells whether a curl function exists. Default `function_exists()`. */
    public function __construct(?\Closure $functionExists = null)
    {
        $this->functionExists = $functionExists ?? function_exists(...);
    }

    /** Whether every curl function exists. The first call checks them; the first false answer of the process logs MISSING. */
    public function usable(): bool
    {
        if ($this->usable === null) {
            $missing = array_filter(self::FUNCTIONS, fn(string $function): bool => !($this->functionExists)($function));
            $this->usable = $missing === [];
            if (!$this->usable && !self::$reported) {
                self::$reported = true;
                Log::write(sprintf(self::MISSING, implode(', ', $missing)));
            }
        }

        return $this->usable;
    }

    /** Tests only: the next check that finds a missing function logs MISSING again. */
    public static function reset(): void
    {
        self::$reported = false;
    }

    /** True once `usable()` answered true. Checks nothing. */
    public function confirmed(): bool
    {
        return $this->usable === true;
    }

    /** `agentcat-php/<SDK version> php/<PHP version>`. */
    public static function userAgent(): string
    {
        return 'agentcat-php/' . SdkVersion::get() . ' php/' . PHP_VERSION;
    }

    /**
     * `$seconds` in whole milliseconds, for `CURLOPT_CONNECTTIMEOUT_MS` and `CURLOPT_TIMEOUT_MS`.
     *
     * @throws \InvalidArgumentException for NAN, for less than 0.0005 (it would round to 0 ms, curl's "no timeout", or
     *                                   to a negative value curl rejects) and for more than MAX_TIMEOUT_SECONDS (INF
     *                                   included): a programming error, the queue passes constants
     */
    public static function timeoutMilliseconds(float $seconds): int
    {
        // Checked before the cast: on PHP 8.5, (int) of a float that is not finite or beyond the int range warns.
        if (is_nan($seconds) || $seconds < 0.0005 || $seconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'A curl timeout must round to 1 through 2147483647 milliseconds, got %s seconds',
                var_export($seconds, true),
            ));
        }

        return (int) round($seconds * 1000);
    }

    /** The bound of one wait: `$seconds` within 0 and `$max`. NAN is 0 before `min()`/`max()` see it (PHP 8.4 changed them). */
    public static function waitSeconds(float $seconds, float $max): float
    {
        return is_nan($seconds) ? 0.0 : max(0.0, min($seconds, $max));
    }

    /**
     * The options of one `POST`. `$capture`, the options that take the response (`CURLOPT_RETURNTRANSFER`, or the
     * write and header functions), come first: curl_setopt_array() stops at the first option it rejects, and a
     * handle without them would print the response body to STDOUT, the stdio MCP stream. Then the request:
     * `$headers` (`Name: value` lines) and the SDK User-Agent, http and https only, no redirects, no signals, and
     * the connect and total timeouts. The response headers stay out of the body.
     *
     * @param array<int, mixed> $capture
     * @param list<string>      $headers
     *
     * @return array<int, mixed>
     */
    public static function postOptions(array $capture, string $url, string $body, array $headers, int $connectTimeoutMs, int $totalTimeoutMs): array
    {
        return $capture + [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            // A blank `Expect:` stops libcurl from waiting for `100 Continue` on large bodies.
            CURLOPT_HTTPHEADER => [...$headers, 'Expect:'],
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $totalTimeoutMs,
        ];
    }

    /** `cURL error <n>: <message>` for a finished transfer whose result is not `CURLE_OK`. */
    public static function transferError(\CurlHandle $handle, int $result): string
    {
        $text = curl_error($handle);

        return sprintf('cURL error %d: %s', $result, $text !== '' ? $text : curl_strerror($result));
    }

    /** `cURL multi error <n>: <message>` for a `curl_multi_*` call that did not return `CURLM_OK`. */
    public static function multiError(int $code): string
    {
        $message = curl_multi_strerror($code); // not nested in sprintf()'s arguments: see `CurlMulti`

        return sprintf('cURL multi error %d: %s', $code, $message);
    }
}
