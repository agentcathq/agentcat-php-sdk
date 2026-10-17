<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Wire\WireEncoder;

/**
 * Turns mcp/sdk values (associative arrays, `\JsonSerializable` schema objects) into the SDK's JSON trees, where
 * objects are `\stdClass` and arrays are lists.
 *
 * @internal
 */
final class Trees
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
     * The tree of a `tools/call` request's arguments: always an object, so empty arguments are `{}` and integer
     * keys stay keys (N8). Nested values are encoded as they are. Never throws: `{}` when JSON cannot carry them.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function arguments(array $arguments): mixed
    {
        try {
            return self::of((object) $arguments);
        } catch (\Throwable) {
            return new \stdClass();
        }
    }

    /**
     * The tree of a tool result. mcp/sdk cannot tell `{}` from `[]`, so an empty-list `structuredContent` is `{}`
     * (header conflict 4).
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

    /**
     * The trees of a `tools/list` result's tools, as a list.
     *
     * @param array<mixed> $tools
     *
     * @return list<mixed>
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function tools(array $tools): array
    {
        /** @var list<mixed> a PHP list encodes as a JSON array, which decodes as a list */
        return self::of(array_values($tools));
    }
}
