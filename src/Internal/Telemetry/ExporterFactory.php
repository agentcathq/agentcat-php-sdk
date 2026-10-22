<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Datadog\DatadogExport;
use AgentCat\Internal\Telemetry\Datadog\DatadogPayload;
use AgentCat\Internal\Telemetry\Otlp\OtlpExport;
use AgentCat\Internal\Telemetry\Otlp\OtlpPayload;
use AgentCat\Internal\Telemetry\PostHog\PostHogExport;
use AgentCat\Internal\Telemetry\PostHog\PostHogPayload;
use AgentCat\Internal\Telemetry\Sentry\SentryExport;
use AgentCat\Internal\Telemetry\Sentry\SentryPayload;
use AgentCat\Internal\Transport\HttpChannel;

/**
 * TypeScript `TelemetryManager.createExporter()`: the exporter of one configuration object.
 *
 * @internal
 */
final class ExporterFactory
{
    /**
     * Null, after the TypeScript `Unknown exporter type: <type>` line, for anything that is not one of the four
     * configuration classes (`Options::$exporters` entries are not validated, Task 4).
     *
     * @throws \InvalidArgumentException an invalid Sentry DSN
     */
    public static function create(mixed $config, HttpChannel $http): ?Exporter
    {
        return match (true) {
            $config instanceof OtlpExporter => new OtlpExport(OtlpPayload::of($config), $http),
            $config instanceof DatadogExporter => new DatadogExport(DatadogPayload::of($config), $http),
            $config instanceof SentryExporter => new SentryExport(SentryPayload::of($config), $http),
            $config instanceof PostHogExporter => new PostHogExport(PostHogPayload::of($config), $http),
            default => self::unknown($config),
        };
    }

    private static function unknown(mixed $config): null
    {
        Log::write('Unknown exporter type: ' . get_debug_type($config));

        return null;
    }
}
