<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

/**
 * One `POST` of an exporter or of diagnostics.
 *
 * @internal
 */
final readonly class HttpRequest
{
    /**
     * @param array<array-key, string> $headers    name => value, sent in this order
     * @param bool                     $background AgentCat's own request (diagnostics): `flush()` neither counts nor waits
     *                                             for it, `shutdown()` does (`HttpChannel::pending()`)
     */
    public function __construct(
        public string $url,
        public array $headers,
        public string $body,
        public bool $background = false,
    ) {}
}
