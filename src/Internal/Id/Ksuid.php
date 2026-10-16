<?php

declare(strict_types=1);

namespace AgentCat\Internal\Id;

/**
 * Segment KSUID: 4-byte big-endian seconds since 2014-05-13 plus 16 payload bytes, base62 encoded.
 *
 * @internal
 */
final class Ksuid
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const int EPOCH_MS = 1_400_000_000_000;
    private const int MAX_SECONDS = 4_294_967_295;
    private const int ENCODED_LENGTH = 27;
    private const int PAYLOAD_LENGTH = 16;
    private const int RAW_LENGTH = 20;

    public static function fromParts(int $timestampMs, string $payload): string
    {
        if (strlen($payload) !== self::PAYLOAD_LENGTH) {
            throw new \InvalidArgumentException('KSUID payload must be exactly 16 bytes');
        }
        if ($timestampMs < self::EPOCH_MS || $timestampMs > self::EPOCH_MS + 1000 * self::MAX_SECONDS) {
            throw new \InvalidArgumentException('KSUID timestamp is out of range');
        }
        $seconds = intdiv($timestampMs - self::EPOCH_MS, 1000);

        $digits = array_map(ord(...), str_split(pack('N', $seconds) . $payload));

        return str_pad(self::convert($digits, 256, 62), self::ENCODED_LENGTH, '0', STR_PAD_LEFT);
    }

    public static function random(?int $timestampMs = null): string
    {
        return self::fromParts($timestampMs ?? (int) (microtime(true) * 1000), random_bytes(self::PAYLOAD_LENGTH));
    }

    /**
     * @return array{timestamp: int, payload: string}|null
     */
    public static function decode(string $encoded): ?array
    {
        if (preg_match('/\A[0-9A-Za-z]{27}\z/', $encoded) !== 1) {
            return null;
        }
        $digits = [];
        foreach (str_split($encoded) as $char) {
            $digits[] = (int) strpos(self::ALPHABET, $char);
        }
        $raw = self::convert($digits, 62, 256);
        if (strlen($raw) > self::RAW_LENGTH) {
            return null;
        }
        $raw = str_pad($raw, self::RAW_LENGTH, "\x00", STR_PAD_LEFT);

        return ['timestamp' => self::readUint32($raw), 'payload' => substr($raw, 4)];
    }

    /** Big-endian unsigned 32-bit integer from the first four bytes. */
    public static function readUint32(string $bytes): int
    {
        return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    }

    /**
     * Long division base conversion, most significant digit first.
     *
     * @param list<int> $digits
     */
    private static function convert(array $digits, int $from, int $to): string
    {
        $out = [];
        while ($digits !== []) {
            $quotient = [];
            $remainder = 0;
            foreach ($digits as $digit) {
                $accumulator = $remainder * $from + $digit;
                $q = intdiv($accumulator, $to);
                $remainder = $accumulator % $to;
                if ($quotient !== [] || $q > 0) {
                    $quotient[] = $q;
                }
            }
            $out[] = $remainder;
            $digits = $quotient;
        }

        if ($to === 62) {
            return implode('', array_map(static fn(int $d): string => self::ALPHABET[$d], array_reverse($out)));
        }

        return implode('', array_map(chr(...), array_reverse($out)));
    }
}
