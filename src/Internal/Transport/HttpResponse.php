<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * The outcome of one `HttpRequest`: the answer of the server, or the error that kept it from answering.
 *
 * @internal
 */
final readonly class HttpResponse
{
    /**
     * @param int     $status 0 when the request failed
     * @param string  $reason the reason phrase of the status line (empty on HTTP/2, like `fetch()`'s `statusText`)
     * @param string  $body   at most `CurlHttpChannel::MAX_BODY_BYTES` of the response body
     * @param ?string $error  `cURL error <n>: <message>` when no answer arrived (TypeScript: the `fetch()` rejection)
     */
    public function __construct(
        public int $status,
        public string $reason,
        public string $body,
        public ?string $error = null,
    ) {}

    /** A request that got no answer. */
    public static function failed(string $error): self
    {
        return new self(0, '', '', $error);
    }

    /** An answer with a 2xx status (`fetch()`'s `response.ok`). */
    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }
}
