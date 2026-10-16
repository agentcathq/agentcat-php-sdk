<?php

declare(strict_types=1);

namespace AgentCat\Internal\Tags;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\Js;

/**
 * Port of the TypeScript `validateTags`: drops invalid customer tags and caps the rest.
 *
 * @internal
 */
final class TagValidator
{
    private const string KEY_PATTERN = '/\A[a-zA-Z0-9$_.:\- ]+\z/';
    private const string INTEGER_KEY_PATTERN = '/\A(0|[1-9][0-9]*)\z/';
    private const int MAX_ARRAY_INDEX = 4294967294;
    private const int MAX_KEY_LENGTH = 32;
    private const int MAX_VALUE_LENGTH = 200;
    private const int MAX_TAGS = 50;

    /**
     * Numeric-string keys come back as PHP int array keys (PHP cannot hold them otherwise); cast with
     * `(object) $result` before encoding so they stay object keys.
     *
     * @return array<array-key, string>|null null when no entry is valid
     */
    public static function validate(mixed $tags): ?array
    {
        $entries = match (true) {
            $tags instanceof \stdClass => get_object_vars($tags),
            is_array($tags) => $tags,
            default => [],
        };

        $valid = [];
        foreach (self::orderLikeJs($entries) as $key => $value) {
            // PHP turns numeric-string array keys into ints; the string form is what JS sees.
            if (self::isValid((string) $key, $value)) {
                $valid[$key] = $value;
            }
        }

        if ($valid === []) {
            return null;
        }

        $excess = count($valid) - self::MAX_TAGS;
        if ($excess > 0) {
            Log::write(sprintf('Dropping %d tag(s) — exceeds maximum of %d entries per event', $excess, self::MAX_TAGS));
            $valid = array_slice($valid, 0, self::MAX_TAGS, true);
        }

        return $valid;
    }

    /**
     * JS `Object.entries` order: integer-like keys ascending, then the other keys in insertion order.
     *
     * @param array<array-key, mixed> $entries
     * @return array<array-key, mixed>
     */
    private static function orderLikeJs(array $entries): array
    {
        $integerLike = [];
        $others = [];
        foreach ($entries as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::INTEGER_KEY_PATTERN, $key) === 1 && (int) $key <= self::MAX_ARRAY_INDEX) {
                $integerLike[$key] = $value;
            } else {
                $others[$key] = $value;
            }
        }
        // String keys that look numeric become int array keys, so sort() by key is numeric here.
        ksort($integerLike, SORT_NUMERIC);

        $ordered = [];
        foreach ($integerLike as $key => $value) {
            $ordered[$key] = $value;
        }
        foreach ($others as $key => $value) {
            $ordered[$key] = $value;
        }

        return $ordered;
    }

    /** @phpstan-assert-if-true string $value */
    private static function isValid(string $key, mixed $value): bool
    {
        $reason = match (true) {
            preg_match(self::KEY_PATTERN, $key) !== 1 => 'key contains invalid characters or is empty',
            Js::utf16Length($key) > self::MAX_KEY_LENGTH => 'key exceeds max length of ' . self::MAX_KEY_LENGTH,
            !is_string($value) => 'non-string value (got ' . self::jsTypeof($value) . ')',
            Js::utf16Length($value) > self::MAX_VALUE_LENGTH => 'value exceeds max length of ' . self::MAX_VALUE_LENGTH,
            str_contains($value, "\n") => 'value contains newline character',
            default => null,
        };
        if ($reason === null) {
            return true;
        }
        Log::write(sprintf('Dropping invalid tag: "%s" — %s', $key, $reason));

        return false;
    }

    private static function jsTypeof(mixed $value): string
    {
        return match (true) {
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => 'object',
        };
    }
}
