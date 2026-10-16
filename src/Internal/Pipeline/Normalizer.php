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

    /**
     * @throws \Throwable whatever a `jsonSerialize()` method throws
     */
    public static function normalize(mixed $input, int $depth = 10, int $maxBreadth = 100, int $maxStringLength = 32_768): mixed
    {
        /** @var \SplObjectStorage<object, mixed> $memo */
        $memo = new \SplObjectStorage();

        return self::visit($input, $depth, $maxBreadth, $maxStringLength, $memo);
    }

    /** @param \SplObjectStorage<object, mixed> $memo */
    private static function visit(mixed $value, int $remainingDepth, int $maxBreadth, int $maxStringLength, \SplObjectStorage $memo): mixed
    {
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
            return self::visit($value->value, $remainingDepth, $maxBreadth, $maxStringLength, $memo);
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
                ? self::visitArray($value, $remainingDepth - 1, $maxBreadth, $maxStringLength, $memo)
                : self::visitObject($value, $remainingDepth - 1, $maxBreadth, $maxStringLength, $memo);
        }
        if (isset($memo[$value])) {
            return self::CIRCULAR;
        }
        if ($value instanceof \JsonSerializable) {
            $memo[$value] = null;
            try {
                return self::visit($value->jsonSerialize(), $remainingDepth, $maxBreadth, $maxStringLength, $memo);
            } finally {
                unset($memo[$value]);
            }
        }
        if ($remainingDepth <= 0) {
            return '[Object]';
        }
        $memo[$value] = null;
        try {
            return self::visitObject(Values::entries($value), $remainingDepth - 1, $maxBreadth, $maxStringLength, $memo);
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
    private static function visitArray(array $items, int $remainingDepth, int $maxBreadth, int $maxStringLength, \SplObjectStorage $memo): array
    {
        $out = [];
        foreach (array_slice($items, 0, $maxBreadth) as $item) {
            $out[] = self::visit($item, $remainingDepth, $maxBreadth, $maxStringLength, $memo);
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
    private static function visitObject(array $entries, int $remainingDepth, int $maxBreadth, int $maxStringLength, \SplObjectStorage $memo): \stdClass
    {
        $out = new \stdClass();
        foreach (array_slice($entries, 0, $maxBreadth, true) as $key => $item) {
            $out->{$key} = self::visit($item, $remainingDepth, $maxBreadth, $maxStringLength, $memo);
        }
        if (count($entries) > $maxBreadth) {
            $out->{'...'} = self::MAX_PROPERTIES;
        }

        return $out;
    }
}
