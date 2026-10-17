<?php

declare(strict_types=1);

namespace AgentCat\Exporters;

/**
 * Sends each event as one span to an OTLP/HTTP JSON collector. Use it in
 * `Options(exporters: ['otlp' => new OtlpExporter(endpoint: 'http://localhost:4318')])`.
 */
final readonly class OtlpExporter
{
    /**
     * @param string                $endpoint The collector base URL. `/v1/traces` is added unless it is there already.
     * @param array<string, string> $headers  Extra request headers, for example an API key. A `Content-Type` here replaces `application/json`. Default: none.
     */
    public function __construct(
        public string $endpoint,
        public array $headers = [],
    ) {}
}
