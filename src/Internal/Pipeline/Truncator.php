<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;
use AgentCat\Internal\Wire\WireEncoder;

/**
 * Caps field lengths, depth and breadth, and keeps the event body under the size budget (TS `truncation.ts`).
 *
 * @internal
 */
final class Truncator
{
    public const int MAX_DEPTH = 10;

    public const int MAX_BREADTH = 100;

    public const int MAX_STRING_LENGTH = 32_768;

    public const int MAX_EVENT_BYTES = 102_400;

    public const int MAX_USER_INTENT_LENGTH = 2_048;

    public const int MAX_ERROR_MESSAGE_LENGTH = 2_048;

    public const int MAX_RESOURCE_NAME_LENGTH = 256;

    public const int MAX_METADATA_LENGTH = 256;

    public const int MAX_STACK_FRAMES = 50;

    public const int MAX_CONTENT_TEXT_LENGTH = 32_768;

    public const string TRUNCATION_SUFFIX = '...';

    private const int LAST_RESORT_ATTEMPTS = 10;

    private const int LAST_RESORT_MIN_LENGTH = 100;

    private const int LAST_RESORT_MARGIN = 200;

    private const int LAST_RESORT_MIN_REDUCTION = 10;

    private const int MAX_CLONE_NESTING = 512;

    /**
     * Returns a new event; the input is not changed.
     *
     * @throws \JsonException when the size cannot be measured
     * @throws \UnexpectedValueException when the size cannot be measured, `error.message` is not a string
     *                                   or `error.frames` is neither a list nor a string
     * @throws \RuntimeException when the last resort meets a closure
     */
    public static function truncate(Event $event): Event
    {
        $r = clone $event;

        $r->userIntent = self::cut($r->userIntent, self::MAX_USER_INTENT_LENGTH);
        $r->resourceName = self::cut($r->resourceName, self::MAX_RESOURCE_NAME_LENGTH);
        $r->serverName = self::cut($r->serverName, self::MAX_METADATA_LENGTH);
        $r->serverVersion = self::cut($r->serverVersion, self::MAX_METADATA_LENGTH);
        $r->clientName = self::cut($r->clientName, self::MAX_METADATA_LENGTH);
        $r->clientVersion = self::cut($r->clientVersion, self::MAX_METADATA_LENGTH);
        if (Values::isObjectLike($r->error) && !$r->error instanceof \DateTimeInterface) {
            $error = self::spread($r->error);
            if (property_exists($error, 'message')) {
                $error->message = self::cutMessage($error->message);
            }
            if (property_exists($error, 'frames')) {
                $error->frames = self::cutFrames($error->frames);
            }
            $r->error = $error;
        }

        if ($r->parameters !== null) {
            $r->parameters = Normalizer::normalize($r->parameters);
        }
        if ($r->response !== null) {
            $r->response = Normalizer::normalize($r->response);
        }
        if ($r->identifyActorData !== null) {
            $r->identifyActorData = Normalizer::normalize($r->identifyActorData);
        }
        if ($r->error !== null) {
            $r->error = Normalizer::normalize($r->error);
        }

        return self::fitToSize($r);
    }

    private static function cut(?string $value, int $max): ?string
    {
        if ($value === null || !Js::exceedsUtf16($value, $max)) {
            return $value;
        }

        return Js::utf16Slice($value, $max) . self::TRUNCATION_SUFFIX;
    }

    private static function cutMessage(mixed $message): mixed
    {
        if ($message === null) {
            return null;
        }
        if (is_string($message)) {
            return self::cut($message, self::MAX_ERROR_MESSAGE_LENGTH);
        }
        if (is_array($message) && array_is_list($message)) {
            return count($message) <= self::MAX_ERROR_MESSAGE_LENGTH
                ? $message
                : Js::string(array_slice($message, 0, self::MAX_ERROR_MESSAGE_LENGTH)) . self::TRUNCATION_SUFFIX;
        }

        throw new \UnexpectedValueException(sprintf('error.message must be a string, got %s', get_debug_type($message)));
    }

    private static function cutFrames(mixed $frames): mixed
    {
        if (!Js::truthy($frames)) {
            return $frames;
        }
        $half = intdiv(self::MAX_STACK_FRAMES, 2);
        if (is_array($frames) && array_is_list($frames)) {
            // The tail never overlaps the head, so a list of 50 or fewer frames comes back whole.
            return array_merge(array_slice($frames, 0, $half), array_slice($frames, max($half, count($frames) - $half)));
        }
        if (is_string($frames)) {
            return Js::utf16Length($frames) <= self::MAX_STACK_FRAMES
                ? $frames
                : array_merge(mb_str_split(Js::utf16Slice($frames, $half)), mb_str_split(Js::utf16SliceEnd($frames, $half)));
        }

        throw new \UnexpectedValueException(sprintf('error.frames must be a list or a string, got %s', get_debug_type($frames)));
    }

    /**
     * TS `{ ...value }`: a new object with the entries of the value; lists get index keys.
     *
     * @param array<array-key, mixed>|object $value
     */
    private static function spread(array|object $value): \stdClass
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
            if (!Values::isObjectLike($value)) {
                return new \stdClass();
            }
        }

        return (object) Values::entries($value);
    }

    private static function fitToSize(Event $event): Event
    {
        if (WireEncoder::byteSize($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }
        $reduced = $event;
        for ($depth = self::MAX_DEPTH - 1; $depth >= 1; --$depth) {
            $reduced = self::renormalized($event, $depth);
            if (WireEncoder::byteSize($reduced) <= self::MAX_EVENT_BYTES) {
                return $reduced;
            }
        }

        return self::cutLargestStrings($reduced);
    }

    private static function renormalized(Event $event, int $depth): Event
    {
        $r = clone $event;
        if ($r->parameters !== null) {
            $r->parameters = Normalizer::normalize($r->parameters, $depth);
        }
        if ($r->response !== null) {
            $r->response = Normalizer::normalize($r->response, $depth);
        }
        if ($r->identifyActorData !== null) {
            $r->identifyActorData = Normalizer::normalize($r->identifyActorData, $depth);
        }
        if ($r->error !== null) {
            $r->error = Normalizer::normalize($r->error, $depth);
        }

        return $r;
    }

    private static function cutLargestStrings(Event $event): Event
    {
        $fields = [];
        foreach (EventFields::toArray($event) as $name => $value) {
            $fields[$name] = self::deepClone($value, 0);
        }
        $result = clone $event;
        for ($attempt = 0; $attempt < self::LAST_RESORT_ATTEMPTS; ++$attempt) {
            EventFields::assign($result, $fields);
            $size = WireEncoder::byteSize($result);
            if ($size <= self::MAX_EVENT_BYTES) {
                return $result;
            }
            $paths = [];
            self::collectStringPaths($fields, [], $paths);
            usort($paths, static fn(array $a, array $b): int => $b['length'] <=> $a['length']);
            $remaining = $size - self::MAX_EVENT_BYTES + self::LAST_RESORT_MARGIN;
            $cut = false;
            foreach ($paths as ['path' => $path, 'length' => $length]) {
                if ($remaining <= 0) {
                    break;
                }
                $reduction = min($remaining, intdiv($length, 2));
                if ($reduction < self::LAST_RESORT_MIN_REDUCTION) {
                    continue;
                }
                self::cutAt($fields, $path, $length - $reduction);
                $remaining -= $reduction;
                $cut = true;
            }
            if (!$cut) {
                break;
            }
        }
        EventFields::assign($result, $fields);

        return $result;
    }

    /**
     * TS `structuredClone`: arrays and objects are copied, closures cannot be cloned.
     *
     * @throws \RuntimeException when the value holds a closure
     * @throws \RangeException   when the value nests deeper than 512 levels (JS `Maximum call stack size exceeded`)
     */
    private static function deepClone(mixed $value, int $nesting): mixed
    {
        if ($nesting > self::MAX_CLONE_NESTING) {
            throw new \RangeException('Maximum call stack size exceeded');
        }
        if ($value instanceof \Closure) {
            throw new \RuntimeException('Closure could not be cloned');
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::deepClone($item, $nesting + 1), $value);
        }
        if (!is_object($value) || $value instanceof \DateTimeInterface || $value instanceof \UnitEnum) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            return self::deepClone($value->jsonSerialize(), $nesting + 1);
        }
        $copy = new \stdClass();
        foreach (Values::entries($value) as $key => $item) {
            $copy->{$key} = self::deepClone($item, $nesting + 1);
        }

        return $copy;
    }

    /**
     * Appends the strings longer than 100 units to `$paths`, depth first, in field and key order.
     *
     * @param list<array-key> $prefix
     * @param list<array{path: list<array-key>, length: int}> $paths
     */
    private static function collectStringPaths(mixed $value, array $prefix, array &$paths): void
    {
        if (is_string($value)) {
            $length = Js::utf16Length($value);
            if ($length > self::LAST_RESORT_MIN_LENGTH) {
                $paths[] = ['path' => $prefix, 'length' => $length];
            }
        } elseif (is_array($value) || $value instanceof \stdClass) {
            foreach (Values::entries($value) as $key => $item) {
                self::collectStringPaths($item, [...$prefix, $key], $paths);
            }
        }
    }

    /**
     * Cuts the string at `$path` to its first `$keep` units plus the suffix. Works in place: `$fields` is the
     * private clone, so a cut never copies the arrays around it.
     *
     * @param array<string, mixed> $fields
     * @param list<array-key> $path a path from collectStringPaths(), so every step is an array or a `\stdClass`
     */
    private static function cutAt(array &$fields, array $path, int $keep): void
    {
        $node = &$fields;
        foreach ($path as $step) {
            if ($node instanceof \stdClass) {
                $node = &$node->{$step};
            } elseif (is_array($node)) {
                $node = &$node[$step];
            }
        }
        if (is_string($node)) {
            $node = Js::utf16Slice($node, $keep) . self::TRUNCATION_SUFFIX;
        }
    }
}
