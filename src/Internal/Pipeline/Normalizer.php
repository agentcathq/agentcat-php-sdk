<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;

/**
 * Caps depth, breadth and string length of a value and turns it into JSON-shaped data (TS `normalize`).
 *
 * Objects come out as `\stdClass`, lists stay lists, and every value that JSON cannot carry becomes a marker string.
 *
 * @internal
 */
final class Normalizer
{
    private const string CIRCULAR = '[Circular ~]';

    private const string MAX_PROPERTIES = '[MaxProperties ~]';

    /** Nesting cap over containers and `jsonSerialize()` expansions; the TS depth does not count expansions. */
    private const int MAX_NESTING = 512;

    /**
     * @throws \Throwable      whatever a `jsonSerialize()` method throws
     * @throws \RangeException when the value nests deeper than 512 levels (JS `Maximum call stack size exceeded`)
     */
    public static function normalize(mixed $input, int $depth = 10, int $maxBreadth = 100, int $maxStringLength = 32_768): mixed
    {
        /** @var \SplObjectStorage<object, mixed> $memo */
        $memo = new \SplObjectStorage();

        return self::visit($input, $depth, $maxBreadth, $maxStringLength, $memo, 0);
    }

    /** @param \SplObjectStorage<object, mixed> $memo */
    private static function visit(mixed $value, int $remainingDepth, int $maxBreadth, int $maxStringLength, \SplObjectStorage $memo, int $nesting): mixed
    {
        if ($nesting > self::MAX_NESTING) {
            throw new \RangeException('Maximum call stack size exceeded');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return match (true) {
                is_nan($value) => '[NaN]',
                is_infinite($value) => $value === INF ? '[Infinity]' : '[-Infinity]',
                default => $value,
            };
        }
        if (is_string($value)) {
            return Js::exceedsUtf16($value, $maxStringLength)
                ? Js::utf16Slice($value, $maxStringLength) . '...'
                : $value;
        }
        if ($value instanceof \Closure) {
            $name = (new \ReflectionFunction($value))->getName();

            return '[Function: ' . (str_contains($name, '{closure') ? '<anonymous>' : $name) . ']';
        }
        if ($value instanceof \DateTimeInterface) {
            return Values::isoDate($value);
        }
        if ($value instanceof \BackedEnum) {
            return self::visit($value->value, $remainingDepth, $maxBreadth, $maxStringLength, $memo, $nesting);
        }
        if ($value instanceof \UnitEnum) {
            return Values::enumMarker($value);
        }
        if (!is_object($value) && !is_array($value)) {
            return Values::resourceMarker($value);
        }
        if (is_array($value)) {
            if ($remainingDepth <= 0) {
                return array_is_list($value) ? '[Array]' : '[Object]';
            }

            return array_is_list($value)
                ? self::visitArray($value, $remainingDepth - 1, $maxBreadth, $maxStringLength, $memo, $nesting + 1)
                : self::visitObject(Values::entries($value), $remainingDepth - 1, $maxBreadth, $maxStringLength, $memo, $nesting + 1);
        }
        if (isset($memo[$value])) {
            return self::CIRCULAR;
        }
        if ($value instanceof \JsonSerializable) {
            $memo[$value] = null;
            try {
                return self::visit($value->jsonSerialize(), $remainingDepth, $maxBreadth, $maxStringLength, $memo, $nesting + 1);
            } finally {
                unset($memo[$value]);
            }
        }
        if ($remainingDepth <= 0) {
            return '[Object]';
        }
        $memo[$value] = null;
        try {
            return self::visitObject(Values::entries($value), $remainingDepth - 1, $maxBreadth, $maxStringLength, $memo, $nesting + 1);
        } finally {
            unset($memo[$value]);
        }
    }

    /**
     * @param array<array-key, mixed> $items
     * @param \SplObjectStorage<object, mixed> $memo
     *
     * @return list<mixed>
     */
    private static function visitArray(array $items, int $remainingDepth, int $maxBreadth, int $maxStringLength, \SplObjectStorage $memo, int $nesting): array
    {
        $out = [];
        foreach (array_slice($items, 0, $maxBreadth) as $item) {
            $out[] = self::visit($item, $remainingDepth, $maxBreadth, $maxStringLength, $memo, $nesting);
        }
        if (count($items) > $maxBreadth) {
            $out[] = self::MAX_PROPERTIES;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param \SplObjectStorage<object, mixed> $memo
     */
    private static function visitObject(array $entries, int $remainingDepth, int $maxBreadth, int $maxStringLength, \SplObjectStorage $memo, int $nesting): \stdClass
    {
        $out = new \stdClass();
        foreach (array_slice($entries, 0, $maxBreadth, true) as $key => $item) {
            $out->{$key} = self::visit($item, $remainingDepth, $maxBreadth, $maxStringLength, $memo, $nesting);
        }
        if (count($entries) > $maxBreadth) {
            $out->{'...'} = self::MAX_PROPERTIES;
        }

        return $out;
    }
}
