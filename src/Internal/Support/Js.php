<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Helpers that make PHP behave like JavaScript where wire output depends on it.
 *
 * @internal
 */
final class Js
{
    /** ECMAScript WhiteSpace + LineTerminator. */
    private const string WHITESPACE = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    /** JavaScript truthiness: false for null, false, 0, 0.0, '' and NaN; true otherwise (including [] and objects). */
    public static function truthy(mixed $value): bool
    {
        if (is_float($value) && is_nan($value)) {
            return false;
        }

        return !in_array($value, [null, false, 0, 0.0, ''], true);
    }

    /** ECMAScript `String.prototype.trim`. Returns invalid UTF-8 unchanged. */
    public static function trim(string $value): string
    {
        $trimmed = preg_replace('/\A' . self::WHITESPACE . '+|' . self::WHITESPACE . '+\z/u', '', $value);

        return $trimmed ?? $value;
    }

    /** Length in UTF-16 code units, like JavaScript `String.length`. */
    public static function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** First `$maxUnits` UTF-16 units; a trailing lone high surrogate is dropped, so the result is valid UTF-8. */
    public static function utf16Slice(string $value, int $maxUnits): string
    {
        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $units = min(intdiv(strlen($utf16), 2), $maxUnits);
        if ($units <= 0) {
            return '';
        }
        $cut = substr($utf16, 0, $units * 2);
        // Little endian: the last byte holds the high byte of the final code unit (0xD8..0xDB = high surrogate).
        $highByte = ord($cut[-1]);
        if ($highByte >= 0xD8 && $highByte <= 0xDB) {
            $cut = substr($cut, 0, -2);
        }

        return mb_convert_encoding($cut, 'UTF-8', 'UTF-16LE');
    }

    /** True when the string is longer than `$max` UTF-16 units. The byte length rules out most strings cheaply. */
    public static function exceedsUtf16(string $value, int $max): bool
    {
        return strlen($value) > $max && self::utf16Length($value) > $max;
    }

    /** Last `$maxUnits` UTF-16 units; a leading lone low surrogate is dropped, so the result is valid UTF-8. */
    public static function utf16SliceEnd(string $value, int $maxUnits): string
    {
        if ($maxUnits <= 0) {
            return '';
        }
        // A negative start beyond the string returns the whole string; a whole string never starts with a low surrogate.
        $utf16 = substr(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8'), -$maxUnits * 2);
        // Little endian: the second byte holds the high byte of the first code unit (0xDC..0xDF = low surrogate).
        $highByte = ord($utf16[1] ?? "\0");
        if ($highByte >= 0xDC && $highByte <= 0xDF) {
            $utf16 = substr($utf16, 2);
        }

        return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
    }

    /** JS `String(value)` for JSON-shaped values: lists join with commas, other containers print `[object Object]`. */
    public static function string(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            is_string($value) => $value,
            is_array($value) && array_is_list($value) => implode(',', array_map(
                static fn(mixed $item): string => $item === null ? '' : self::string($item),
                $value,
            )),
            default => '[object Object]',
        };
    }

    /** ECMAScript `Number::toString` (radix 10). */
    private static function number(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value === INF ? 'Infinity' : '-Infinity';
        }
        if ($value < 0) {
            return '-' . self::number(-$value);
        }
        if ($value === 0.0) {
            return '0'; // also -0.0
        }

        // Shortest round trip digits (serialize_precision = -1), then lay them out like ECMAScript does.
        $parts = explode('e', (string) json_encode($value));
        $exponent = intval($parts[1] ?? 0);
        $mantissa = explode('.', $parts[0]);
        $whole = $mantissa[0];
        $fraction = $mantissa[1] ?? '';
        $all = $whole . $fraction;
        $digits = ltrim($all, '0');
        $point = strlen($whole) + $exponent - (strlen($all) - strlen($digits));
        $digits = rtrim($digits, '0');
        $count = strlen($digits);

        return match (true) {
            $point > 21 || $point <= -6 => self::exponential($digits, $point - 1),
            $count <= $point => $digits . str_repeat('0', $point - $count),
            0 < $point => substr($digits, 0, $point) . '.' . substr($digits, $point),
            default => '0.' . str_repeat('0', -$point) . $digits,
        };
    }

    private static function exponential(string $digits, int $exponent): string
    {
        $mantissa = strlen($digits) === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);

        return $mantissa . 'e' . sprintf('%+d', $exponent);
    }
}
