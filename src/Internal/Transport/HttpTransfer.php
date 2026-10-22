<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * One request of `CurlHttpChannel` in transfer: its callback, and the part of the response that has arrived. The
 * header and write functions of its handle fill it; nothing else holds it.
 *
 * @internal
 */
final class HttpTransfer
{
    /** The reason phrase of the last status line. */
    public string $reason = '';

    /** At most `CurlHttpChannel::MAX_BODY_BYTES` of the body. */
    public string $body = '';

    /**
     * @param \Closure(HttpResponse): void $onDone
     * @param bool                        $background `HttpRequest::$background`
     */
    public function __construct(
        public readonly \Closure $onDone,
        public readonly bool $background,
    ) {}
}
