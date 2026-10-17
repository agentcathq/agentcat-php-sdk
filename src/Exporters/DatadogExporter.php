<?php

declare(strict_types=1);

namespace AgentCat\Exporters;

/**
 * Sends each event to Datadog's log and metric intake (not APM traces). Use it in
 * `Options(exporters: ['datadog' => new DatadogExporter(apiKey: '…', site: 'datadoghq.com', service: 'my-mcp')])`.
 */
final readonly class DatadogExporter
{
    /**
     * @param string      $apiKey  Your Datadog API key.
     * @param string      $site    Your Datadog site, for example `datadoghq.com` or `datadoghq.eu`.
     * @param string      $service The service name of the logs and metrics.
     * @param string|null $env     Adds an `env:` tag to the logs and metrics. Default: null (no tag).
     */
    public function __construct(
        public string $apiKey,
        public string $site,
        public string $service,
        public ?string $env = null,
    ) {}
}
