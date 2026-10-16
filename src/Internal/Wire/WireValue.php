<?php

declare(strict_types=1);

namespace AgentCat\Internal\Wire;

use AgentCat\Internal\Support\Values;

/**
 * Turns any PHP value into JSON-ready data with `JSON.stringify` semantics.
 *
 * @internal
 */
final class WireValue
{
    public const int MAX_DEPTH = 512;

    /**
     * @throws \UnexpectedValueException on a cycle or when nesting is deeper than MAX_DEPTH
     */
    public static function from(mixed $value): mixed
    {
        /** @var \WeakMap<object, true> $ancestors */
        $ancestors = new \WeakMap();

        return self::walk($value, $ancestors, 0);
    }

    /** @param \WeakMap<object, true> $ancestors */
    private static function walk(mixed $value, \WeakMap $ancestors, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('Maximum nesting depth of ' . self::MAX_DEPTH . ' exceeded');
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \Closure) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Values::isoDate($value);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return Values::enumMarker($value);
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? array_map(static fn(mixed $item): mixed => self::walk($item, $ancestors, $depth + 1), $value)
                : self::object($value, $ancestors, $depth);
        }
        if (!is_object($value)) {
            return Values::resourceMarker($value);
        }
        if (isset($ancestors[$value])) {
            throw new \UnexpectedValueException('Converting circular structure to JSON');
        }
        $ancestors[$value] = true;
        try {
            return $value instanceof \JsonSerializable
                ? self::walk($value->jsonSerialize(), $ancestors, $depth + 1)
                : self::object(Values::entries($value), $ancestors, $depth);
        } finally {
            unset($ancestors[$value]);
        }
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param \WeakMap<object, true> $ancestors
     */
    private static function object(array $entries, \WeakMap $ancestors, int $depth): \stdClass
    {
        $object = new \stdClass();
        foreach ($entries as $key => $item) {
            if ($item instanceof \Closure) {
                continue;
            }
            $object->{(string) $key} = self::walk($item, $ancestors, $depth + 1);
        }

        return $object;
    }
}
