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
}
