<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

use AgentCat\Internal\Wire\WireValue;

/**
 * `JSON.stringify` for exporter and diagnostics bodies: shortest round-trip floats whatever php.ini says (06 AC-64),
 * no escaped slashes or Unicode, U+FFFD for invalid UTF-8. On a host that disables `ini_set()` or `ini_get()`
 * (`disable_functions`), floats follow php.ini's `serialize_precision` (PHP's default, -1, is the shortest round trip).
 *
 * @internal
 */
final class ExportJson
{
    private const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether `ini_set()` and `ini_get()` exist; default `function_exists()`
     *
     * @throws \JsonException
     * @throws \UnexpectedValueException on a cycle or too deep nesting
     */
    public static function encode(mixed $value, ?\Closure $functionExists = null): string
    {
        $exists = $functionExists ?? function_exists(...);
        if (!$exists('ini_set') || !$exists('ini_get')) {
            return json_encode(WireValue::from($value), self::FLAGS);
        }
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            return json_encode(WireValue::from($value), self::FLAGS);
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    /** An empty PHP array in a map position is `{}` (06 AC-46). */
    public static function map(mixed $value): mixed
    {
        return $value === [] ? new \stdClass() : $value;
    }
}
