<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * D59, D62: mcp/sdk and laravel/mcp send their JSON answers without `Content-Length`. On a SAPI that sends a response
 * only when the script ends (mod_php, php-cgi, `php -S`: `FinishRequest::responseEndsWithTheScript()`), the client of
 * such an answer then waits for AgentCat's hooks and delivery. So there, the framework bridges give the plain answer of
 * a tracked server its length, and with D57 the client has all of it before the shutdown function delivers. This is
 * their one rule; each bridge hands it its framework's response as plain values.
 *
 * @internal
 */
final readonly class ContentLength
{
    /** @var \Closure(): bool */
    private \Closure $headersSent;

    /** @param (\Closure(): bool)|null $headersSent default `headers_sent()` */
    public function __construct(?\Closure $headersSent = null)
    {
        $this->headersSent = $headersSent ?? static fn(): bool => headers_sent();
    }

    /**
     * The length in bytes to give an answer, or null when it goes as it is: an answer to a HEAD request (its length
     * would be the GET answer's, RFC 9110 §8.6, but `Response::prepare()` has emptied the body), an answer without a body
     * (informational, 204, 304, as `Response::prepare()` treats them), a streamed one (SSE), one with a `Content-Length`
     * or `Transfer-Encoding` of its own, and one after stray output (headers that went out already, or bytes waiting in
     * an output buffer, which go out first and would make the length short).
     *
     * @param string                  $method  the request's method (`Request::getMethod()`, upper case)
     * @param array<array-key, mixed> $headers the answer's headers by name, in any case (`HeaderBag::all()`)
     * @param string|false            $content the answer's body; false for a streamed answer (`StreamedResponse`)
     */
    public function of(string $method, int $status, array $headers, string|false $content): ?string
    {
        if ($content === false || $method === 'HEAD' || $status < 200 || $status === 204 || $status === 304) {
            return null;
        }
        $headers = array_change_key_case($headers);
        if (array_key_exists('content-length', $headers) || array_key_exists('transfer-encoding', $headers)) {
            return null;
        }
        if (($this->headersSent)() || $this->outputWaits()) {
            return null; // stray output goes out before the answer: a length of the answer alone would cut its end
        }

        return (string) strlen($content);
    }

    /** Some output buffer level holds bytes, which PHP sends before the answer. */
    private function outputWaits(): bool
    {
        foreach (ob_get_status(true) as $status) {
            $used = is_array($status) ? $status['buffer_used'] ?? null : null;
            if (is_int($used) && $used > 0) {
                return true;
            }
        }

        return false;
    }
}
