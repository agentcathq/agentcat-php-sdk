<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\Js;

/**
 * `exporters` from a framework config file: each `['type' => …]` array becomes its config object. A bad entry logs
 * one line and is skipped; the others are kept. Never throws.
 *
 * @internal
 */
final class ExporterConfigs
{
    private const array FIELDS = [
        'otlp' => ['endpoint', 'headers'],
        'datadog' => ['apiKey', 'site', 'service', 'env'],
        'sentry' => ['dsn', 'environment', 'release', 'enableTracing'],
        'posthog' => ['apiKey', 'host', 'enableAITracing'],
    ];

    /**
     * @param array<array-key, mixed> $entries
     *
     * @return array<array-key, OtlpExporter|DatadogExporter|SentryExporter|PostHogExporter>
     */
    public static function fromArray(array $entries): array
    {
        $configs = [];
        foreach ($entries as $key => $entry) {
            $name = (string) $key;
            try {
                $config = match (true) {
                    $entry instanceof OtlpExporter, $entry instanceof DatadogExporter, $entry instanceof SentryExporter, $entry instanceof PostHogExporter => $entry,
                    is_array($entry) => self::one($name, $entry),
                    default => throw new \InvalidArgumentException('exporter configuration must be an array, got ' . get_debug_type($entry)),
                };
            } catch (\InvalidArgumentException $e) {
                Log::write(sprintf('Failed to initialize exporter %s: %s', $name, ErrorText::of($e)));

                continue;
            }
            if ($config !== null) {
                $configs[$key] = $config;
            }
        }

        return $configs;
    }

    /** @param array<array-key, mixed> $entry */
    private static function one(string $name, array $entry): OtlpExporter|DatadogExporter|SentryExporter|PostHogExporter|null
    {
        $type = $entry['type'] ?? null;
        $fields = is_string($type) ? (self::FIELDS[$type] ?? null) : null;
        if ($fields === null) {
            Log::write('Unknown exporter type: ' . (array_key_exists('type', $entry) ? Js::string($type) : 'undefined'));

            return null;
        }
        foreach (array_keys($entry) as $key) {
            if ($key !== 'type' && !in_array((string) $key, $fields, true)) {
                Log::write(sprintf('WARN: Unknown AgentCat option "exporters.%s.%s" ignored', $name, $key));
            }
        }

        return match ($type) {
            'otlp' => new OtlpExporter(self::string($entry, 'endpoint'), self::headers($entry)),
            'datadog' => new DatadogExporter(self::string($entry, 'apiKey'), self::string($entry, 'site'), self::string($entry, 'service'), self::optional($entry, 'env')),
            'sentry' => new SentryExporter(self::string($entry, 'dsn'), self::optional($entry, 'environment'), self::optional($entry, 'release'), self::bool($entry, 'enableTracing')),
            default => new PostHogExporter(self::string($entry, 'apiKey'), self::optional($entry, 'host'), self::bool($entry, 'enableAITracing')),
        };
    }

    /** @param array<array-key, mixed> $entry */
    private static function string(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) ? $value : throw self::invalid($key, 'string', $value);
    }

    /** @param array<array-key, mixed> $entry */
    private static function optional(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return $value === null || is_string($value) ? $value : throw self::invalid($key, 'string or null', $value);
    }

    /** @param array<array-key, mixed> $entry */
    private static function bool(array $entry, string $key): bool
    {
        $value = $entry[$key] ?? false;

        return is_bool($value) ? $value : throw self::invalid($key, 'bool', $value);
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return array<string, string>
     */
    private static function headers(array $entry): array
    {
        $value = $entry['headers'] ?? [];
        $headers = [];
        foreach (is_array($value) ? $value : throw self::invalid('headers', 'array<string, string>', $value) as $header => $text) {
            $headers[(string) $header] = is_string($text) ? $text : throw self::invalid('headers', 'array<string, string>', $value);
        }

        return $headers;
    }

    private static function invalid(string $key, string $expected, mixed $value): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf('exporter option "%s" must be %s, got %s', $key, $expected, get_debug_type($value)));
    }
}
