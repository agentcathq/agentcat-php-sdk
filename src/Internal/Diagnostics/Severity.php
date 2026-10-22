<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

/**
 * The OTLP severity of a diagnostics record.
 *
 * @internal
 */
final class Severity
{
    /**
     * TS `inferSeverity`, on the whole line (prefix included): `fail` or `error` in any case is ERROR, else
     * `Warning:` is WARN, else INFO.
     *
     * @return array{0: int, 1: string} the severity number and text
     */
    public static function of(string $line): array
    {
        if (preg_match('/fail|error/i', $line) === 1) {
            return [17, 'ERROR'];
        }

        return str_contains($line, 'Warning:') ? [13, 'WARN'] : [9, 'INFO'];
    }
}
