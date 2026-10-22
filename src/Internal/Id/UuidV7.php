<?php

declare(strict_types=1);

namespace AgentCat\Internal\Id;

use AgentCat\Internal\Support\Js;

/**
 * TypeScript `toUUIDv7()` (PostHog exporter): a deterministic UUIDv7 from a prefixed ID. The timestamp comes from
 * the KSUID inside the ID, or else from the SHA-256 of the ID (never the clock); the other bits come from the hash.
 *
 * @internal
 */
final class UuidV7
{
    private const int LENGTH = 27;

    private const int BYTES = 20;

    public static function fromPrefixedId(string $prefixedId): string
    {
        $hash = hash('sha256', $prefixedId, true);
        $millis = self::ksuidMillis(preg_replace('/\A[a-z]+_/', '', $prefixedId) ?? $prefixedId);
        if ($millis === null) {
            /** @var array{high: int, low: int} $parts */
            $parts = unpack('x10/Nhigh/nlow', $hash);
            $millis = ($parts['high'] << 16) | $parts['low'];
        }
        $bytes = substr(pack('J', $millis), 2)
            . chr(0x70 | (ord($hash[0]) & 0x0F)) . $hash[1]
            . chr(0x80 | (ord($hash[2]) & 0x3F)) . substr($hash, 3, 7);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** `KSUID.parse($encoded).date.getTime()`; null where TypeScript throws (wrong length, value above 160 bits). */
    public static function ksuidMillis(string $encoded): ?int
    {
        if (Js::utf16Length($encoded) !== self::LENGTH) {
            return null;
        }
        $digits = [];
        foreach (mb_str_split($encoded, 1, 'UTF-8') as $char) {
            $code = mb_ord($char, 'UTF-8');
            if ($code > 0xFFFF) {
                $code = 0xD800 + (($code - 0x10000) >> 10); // JS charCodeAt(0) of an astral code point: its high surrogate
            }
            $digits[] = $code < 58 ? $code - 48 : ($code < 91 ? $code - 55 : $code - 61);
        }
        $bytes = array_fill(0, self::BYTES, 0);
        $offset = self::BYTES;
        while ($digits !== []) {
            if ($offset === 0) {
                return null; // RangeError: Fixed length of 20 is too small
            }
            $quotients = [];
            $remainder = 0;
            foreach ($digits as $digit) {
                $accumulator = $digit + $remainder * 62;
                $quotient = intdiv($accumulator, 256);
                if ($accumulator % 256 !== 0 && $accumulator < 0) {
                    --$quotient; // Math.floor, not truncation
                }
                $remainder = $accumulator % 256; // JS %: the sign of the dividend, like PHP
                if ($quotients !== [] || $quotient > 0) {
                    $quotients[] = $quotient;
                }
            }
            $bytes[--$offset] = $remainder & 0xFF; // Buffer.from() keeps the low byte
            $digits = $quotients;
        }

        return Ksuid::readUint32(pack('C4', ...array_slice($bytes, 0, 4))) * 1000 + Ksuid::EPOCH_MS;
    }
}
