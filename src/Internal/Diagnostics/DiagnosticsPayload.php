<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Telemetry\ExportJson;

/**
 * The OTLP/JSON logs body of one diagnostics request (TS `flushDiagnostics`).
 *
 * @internal
 */
final class DiagnosticsPayload
{
    /**
     * @param list<array{key: string, value: array{stringValue: string}}> $resource
     * @param list<array<string, mixed>>                                  $records
     *
     * @throws \JsonException
     * @throws \UnexpectedValueException
     */
    public static function encode(array $resource, array $records, string $version): string
    {
        return ExportJson::encode(['resourceLogs' => [[
            'resource' => ['attributes' => $resource],
            'scopeLogs' => [['scope' => ['name' => Constants::DIAGNOSTICS_SCOPE_NAME, 'version' => $version], 'logRecords' => $records]],
        ]]]);
    }
}
