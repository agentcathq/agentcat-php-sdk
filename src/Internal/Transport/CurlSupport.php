<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Internal\Logging\Log;

/**
 * What `CurlTransport` and `CurlHttpChannel` share about ext-curl: the check that every curl function they call
 * exists (a PHP without ext-curl, or with some of its functions in `disable_functions`), made once and logged once
 * (D32), the options of a `POST`, and the texts of a failed transfer and of a failed multi handle.
 *
 * @internal
 */
final class CurlSupport
{
    /** Every curl function `CurlTransport` and `CurlHttpChannel` call. */
    private const array FUNCTIONS = [
        'curl_init', 'curl_setopt_array', 'curl_error', 'curl_strerror', 'curl_getinfo', 'curl_multi_init',
        'curl_multi_add_handle', 'curl_multi_remove_handle', 'curl_multi_exec', 'curl_multi_info_read',
        'curl_multi_select', 'curl_multi_strerror',
    ];

    /** Whether every function in FUNCTIONS exists; null until `usable()` checked it. */
    private ?bool $usable = null;

    /** @var \Closure(string): bool */
    private readonly \Closure $functionExists;

    /**
     * @param string                        $missingLine    the line logged when a function is missing; `%s` is the comma-separated list
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether a curl function exists. Default `function_exists()`.
     */
    public function __construct(private readonly string $missingLine, ?\Closure $functionExists = null)
    {
        $this->functionExists = $functionExists ?? function_exists(...);
    }

    /** Whether every curl function exists. The first call checks them; a false answer logs one line. */
    public function usable(): bool
    {
        if ($this->usable === null) {
            $missing = array_filter(self::FUNCTIONS, fn(string $function): bool => !($this->functionExists)($function));
            $this->usable = $missing === [];
            if (!$this->usable) {
                Log::write(sprintf($this->missingLine, implode(', ', $missing)));
            }
        }

        return $this->usable;
    }

    /** True once `usable()` answered true. Checks nothing. */
    public function confirmed(): bool
    {
        return $this->usable === true;
    }

    /**
     * The options of one `POST`: `$headers` (`Name: value` lines) and the SDK User-Agent, http and https only, no
     * redirects, no signals, connect and total timeouts rounded to milliseconds. The response headers stay out of
     * the body.
     *
     * @param list<string> $headers
     *
     * @return array<int, mixed>
     */
    public static function postOptions(string $url, string $body, array $headers, float $connectTimeoutSeconds, float $totalTimeoutSeconds): array
    {
        return [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            // A blank `Expect:` stops libcurl from waiting for `100 Continue` on large bodies.
            CURLOPT_HTTPHEADER => [...$headers, 'Expect:'],
            CURLOPT_USERAGENT => CurlTransport::userAgent(),
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($totalTimeoutSeconds * 1000),
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
        return sprintf('cURL multi error %d: %s', $code, curl_multi_strerror($code));
    }
}
