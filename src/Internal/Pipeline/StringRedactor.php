<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Support\Values;

/**
 * Applies the customer's string redactor to every string of an event, except protected top-level fields.
 *
 * @internal
 */
final class StringRedactor
{
    /** Top-level fields whose whole subtree is never passed to the redactor (TS order; `server` and `identifyData` have no PHP field). */
    public const array PROTECTED_FIELDS = [
        'sessionId',
        'id',
        'projectId',
        'server',
        'serverName',
        'serverVersion',
        'clientName',
        'clientVersion',
        'identifyActorGivenId',
        'identifyActorName',
        'identifyData',
        'resourceName',
        'eventType',
        'actorId',
        'tags',
        'properties',
    ];

    private const int MAX_DEPTH = 512;

    /**
     * @param \Closure(string): string $redactor
     *
     * @throws \UnexpectedValueException when the redactor returns a non-string
     * @throws \RangeException           when the event nests deeper than 512 levels
     */
    public static function redact(Event $event, \Closure $redactor): Event
    {
        $result = clone $event;
        /** @var \WeakMap<object, \stdClass> $seen */
        $seen = new \WeakMap();
        $fields = [];
        foreach (EventFields::toArray($event) as $name => $value) {
            $fields[$name] = self::walk($value, $redactor, in_array($name, self::PROTECTED_FIELDS, true), $seen, 0);
        }
        EventFields::assign($result, $fields);

        return $result;
    }

    /**
     * @param \Closure(string): string      $redactor
     * @param \WeakMap<object, \stdClass>   $seen
     */
    private static function walk(mixed $value, \Closure $redactor, bool $protected, \WeakMap $seen, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RangeException('Maximum call stack size exceeded');
        }
        if (is_string($value)) {
            return $protected ? $value : self::applyRedactor($redactor, $value);
        }
        if (is_array($value)) {
            return self::walkArray($value, $redactor, $protected, $seen, $depth);
        }
        if (!is_object($value) || $value instanceof \DateTimeInterface || $value instanceof \Closure || $value instanceof \UnitEnum) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            return self::walk($value->jsonSerialize(), $redactor, $protected, $seen, $depth + 1);
        }
        if (isset($seen[$value])) {
            return $seen[$value];
        }
        $clone = new \stdClass();
        $seen[$value] = $clone;
        foreach (Values::entries($value) as $key => $item) {
            if ($item instanceof \Closure) {
                continue;
            }
            $clone->{(string) $key} = self::walk($item, $redactor, $protected, $seen, $depth + 1);
        }

        return $clone;
    }

    /**
     * @param array<array-key, mixed>     $value
     * @param \Closure(string): string    $redactor
     * @param \WeakMap<object, \stdClass> $seen
     *
     * @return array<array-key, mixed>
     */
    private static function walkArray(array $value, \Closure $redactor, bool $protected, \WeakMap $seen, int $depth): array
    {
        $isList = array_is_list($value);
        $result = [];
        foreach ($value as $key => $item) {
            if (!$isList && $item instanceof \Closure) {
                continue;
            }
            $result[$key] = self::walk($item, $redactor, $protected, $seen, $depth + 1);
        }

        return $result;
    }

    /** @param \Closure(string): string $redactor */
    private static function applyRedactor(\Closure $redactor, string $value): string
    {
        $result = $redactor($value);
        // @phpstan-ignore function.alreadyNarrowedType (a customer callback can return any type at runtime)
        if (!is_string($result)) {
            throw new \UnexpectedValueException(sprintf('redactSensitiveInformation must return a string, got %s', get_debug_type($result)));
        }

        return $result;
    }
}
