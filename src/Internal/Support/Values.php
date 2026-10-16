<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Helpers that give PHP values the JavaScript meaning wire output depends on.
 *
 * @internal
 */
final class Values
{
    public static function isoDate(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function enumMarker(\UnitEnum $case): string
    {
        return '[Enum: ' . $case::class . '::' . $case->name . ']';
    }

    public static function resourceMarker(mixed $resource): string
    {
        return '[Resource: ' . (is_resource($resource) ? get_resource_type($resource) : 'closed') . ']';
    }

    /**
     * JS `Object.entries`: array entries, or the public properties of an object (called from outside its scope).
     *
     * @param array<array-key, mixed>|object $value
     *
     * @return array<array-key, mixed>
     */
    public static function entries(array|object $value): array
    {
        return is_array($value) ? $value : get_object_vars($value);
    }

    /**
     * JS `typeof value === "object"` for values a walker can open: arrays and objects except closures and enums.
     *
     * @phpstan-assert-if-true array<array-key, mixed>|object $value
     */
    public static function isObjectLike(mixed $value): bool
    {
        return is_array($value) || (is_object($value) && !$value instanceof \Closure && !$value instanceof \UnitEnum);
    }

    /** @param array<array-key, mixed>|object $value */
    public static function has(array|object $value, string $key): bool
    {
        return array_key_exists($key, self::entries($value));
    }

    /** @param array<array-key, mixed>|object $value */
    public static function get(array|object $value, string $key): mixed
    {
        return self::entries($value)[$key] ?? null;
    }
}
