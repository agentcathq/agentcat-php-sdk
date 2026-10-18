<?php

declare(strict_types=1);

namespace AgentCat\Internal\Json;

use AgentCat\Internal\Wire\WireEncoder;

/**
 * Turns MCP library values (associative arrays, `\JsonSerializable` objects) into the SDK's JSON trees, where
 * objects are `\stdClass` and arrays are lists. The adapters of every library share these rules.
 *
 * @internal
 */
final class JsonTree
{
    /**
     * The JSON tree of a value, as the wire would carry it. Invalid UTF-8 becomes U+FFFD. What a `jsonSerialize()`
     * throws passes through.
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function of(mixed $value): mixed
    {
        return Json::decode(json_encode($value, WireEncoder::FLAGS));
    }

    /**
     * The tree of an associative array as an object, for example a `tools/call` request's arguments: an empty array
     * is `{}` and integer keys stay keys (N8). Nested values are encoded as they are. Never throws: `{}` when JSON
     * cannot carry the value.
     *
     * @param array<array-key, mixed> $value
     */
    public static function object(array $value): \stdClass
    {
        try {
            $tree = self::of((object) $value);
        } catch (\Throwable) {
            $tree = null;
        }

        return $tree instanceof \stdClass ? $tree : new \stdClass();
    }

    /**
     * The tree of a tool result. A library's PHP arrays cannot tell `{}` from `[]`, so an empty-list
     * `structuredContent` is `{}` (C1 header conflict 4).
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function result(mixed $result): mixed
    {
        $tree = self::of($result);
        if ($tree instanceof \stdClass && ($tree->structuredContent ?? null) === []) {
            $tree->structuredContent = new \stdClass();
        }

        return $tree;
    }
}
