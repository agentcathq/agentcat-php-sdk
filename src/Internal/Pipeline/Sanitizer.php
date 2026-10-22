<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;

/**
 * Replaces non-text content blocks in the response and large base64 strings in the parameters
 * (TS `sanitizeEvent`, including its quirks).
 *
 * @internal
 */
final class Sanitizer
{
    public const string IMAGE_MARKER = '[image content redacted - not supported by AgentCat]';

    public const string AUDIO_MARKER = '[audio content redacted - not supported by AgentCat]';

    public const string BINARY_RESOURCE_MARKER = '[binary resource content redacted - not supported by AgentCat]';

    public const string BINARY_DATA_MARKER = '[binary data redacted - not supported by AgentCat]';

    public const int SIZE_GATE = 10240;

    public const string BASE64_PATTERN = '/\A[A-Za-z0-9+\/\n\r]++=*+\z/';

    private const int MAX_DEPTH = 512;

    private const string CYCLE_MESSAGE = 'Maximum call stack size exceeded';

    /**
     * Returns a new event; only `response` and `parameters` change.
     *
     * @throws \RangeException when `parameters` or `structuredContent` has a cycle or nests deeper than 512 levels
     */
    public static function sanitize(Event $event): Event
    {
        $result = clone $event;
        if ($result->response !== null) {
            $result->response = self::sanitizeResponse($result->response);
        }
        if ($result->parameters !== null) {
            /** @var \WeakMap<object, true> $ancestors */
            $ancestors = new \WeakMap();
            $result->parameters = self::sanitizeParameters($result->parameters, $ancestors, 0);
        }

        return $result;
    }

    private static function sanitizeResponse(mixed $response): mixed
    {
        if ($response instanceof \JsonSerializable) {
            $response = $response->jsonSerialize();
        }
        if (!Values::isObjectLike($response) || $response instanceof \DateTimeInterface) {
            return $response;
        }

        $copy = (object) Values::entries($response); // a list becomes an index-keyed object, as `{ ...response }` does
        if (is_array($copy->content ?? null) && array_is_list($copy->content)) {
            $copy->content = array_map(self::block(...), $copy->content);
        }
        if (Values::isObjectLike($copy->structuredContent ?? null)) {
            /** @var \WeakMap<object, true> $ancestors */
            $ancestors = new \WeakMap();
            $copy->structuredContent = self::sanitizeParameters($copy->structuredContent, $ancestors, 0);
        }

        return $copy;
    }

    private static function block(mixed $block): mixed
    {
        if (!Values::isObjectLike($block)) {
            return $block;
        }
        $missing = !Values::has($block, 'type');
        $type = $missing ? null : Values::get($block, 'type');

        return match (true) {
            $type === 'text', $type === 'resource_link' => $block,
            $type === 'image' => self::textBlock(self::IMAGE_MARKER),
            $type === 'audio' => self::textBlock(self::AUDIO_MARKER),
            $type === 'resource' => self::resourceBlock($block),
            default => self::textBlock('[unsupported content type "' . ($missing ? 'undefined' : Js::string($type)) . '" redacted - not supported by AgentCat]'),
        };
    }

    /** @param array<array-key, mixed>|object $block */
    private static function resourceBlock(array|object $block): mixed
    {
        $resource = Values::get($block, 'resource');
        if (Js::truthy($resource) && Values::isObjectLike($resource) && Values::has($resource, 'blob')) {
            return self::textBlock(self::BINARY_RESOURCE_MARKER);
        }

        return $block;
    }

    private static function textBlock(string $text): \stdClass
    {
        $block = new \stdClass();
        $block->type = 'text';
        $block->text = $text;

        return $block;
    }

    /** @param \WeakMap<object, true> $ancestors */
    private static function sanitizeParameters(mixed $value, \WeakMap $ancestors, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RangeException(self::CYCLE_MESSAGE);
        }

        if (is_string($value)) {
            return strlen($value) >= self::SIZE_GATE && preg_match(self::BASE64_PATTERN, $value) === 1
                ? self::BINARY_DATA_MARKER
                : $value;
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::sanitizeParameters($item, $ancestors, $depth + 1), $value);
        }
        if (!is_object($value) || $value instanceof \DateTimeInterface || $value instanceof \Closure || $value instanceof \UnitEnum) {
            return $value;
        }

        if (isset($ancestors[$value])) {
            throw new \RangeException(self::CYCLE_MESSAGE);
        }
        $ancestors[$value] = true;
        try {
            if ($value instanceof \JsonSerializable) {
                return self::sanitizeParameters($value->jsonSerialize(), $ancestors, $depth + 1);
            }
            return (object) array_map(
                static fn(mixed $item): mixed => self::sanitizeParameters($item, $ancestors, $depth + 1),
                Values::entries($value),
            );
        } finally {
            unset($ancestors[$value]);
        }
    }
}
