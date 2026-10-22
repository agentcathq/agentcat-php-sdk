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
     * @param bool                     $background            AgentCat's own request (diagnostics): `flush()` neither
     *                                                        counts nor waits for it, `shutdown()` does (`HttpChannel::pending()`)
     * @param float|null               $connectTimeoutSeconds null: the channel's (`CurlTransport::CONNECT_TIMEOUT_SECONDS`)
     * @param float|null               $totalTimeoutSeconds   null: the channel's (`CurlTransport::TOTAL_TIMEOUT_SECONDS`)
     */
    public function __construct(
        public string $url,
        public array $headers,
        public string $body,
        public bool $background = false,
        public ?float $connectTimeoutSeconds = null,
        public ?float $totalTimeoutSeconds = null,
    ) {}
}
