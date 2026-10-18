<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Json\JsonTree;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;

/**
 * Turns mcp/sdk values (associative arrays, `\JsonSerializable` schema objects) into the SDK's JSON trees, where
 * objects are `\stdClass` and arrays are lists.
 *
 * @internal
 */
final class Trees
{
    /**
     * The JSON tree of a value, as the wire would carry it (`JsonTree::of()`).
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function of(mixed $value): mixed
    {
        return JsonTree::of($value);
    }

    /**
     * The tree of a tool result (`JsonTree::result()`): mcp/sdk cannot tell `{}` from `[]`, so an empty-list
     * `structuredContent` is `{}` (header conflict 4).
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function result(mixed $result): mixed
    {
        return JsonTree::result($result);
    }

    /**
     * A `tools/list` result, its wire tree and the tree's tools, or null when the result is not a `ListToolsResult`
     * whose `tools` is a JSON array on the wire. The listing and the registry rebuild read the same tools this way.
     *
     * @return array{ListToolsResult, \stdClass, list<mixed>}|null
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function listing(mixed $result): ?array
    {
        if (!$result instanceof ListToolsResult) {
            return null;
        }
        $tree = self::of($result);
        if (!$tree instanceof \stdClass || !isset($tree->tools) || !is_array($tree->tools) || !array_is_list($tree->tools)) {
            return null;
        }

        return [$result, $tree, $tree->tools];
    }

    /**
     * A tool result as the library's type: the result itself when it is a `CallToolResult`, else what
     * `CallToolResult::fromArray()` reads from its tree (the way the mcp/sdk client reads an answer), or a result with
     * no content when the tree is not a tool result. Never throws.
     */
    public static function callToolResult(mixed $result): CallToolResult
    {
        if ($result instanceof CallToolResult) {
            return $result;
        }
        try {
            /** @var array{content: array<mixed>, isError?: bool, _meta?: array<string, mixed>, structuredContent?: mixed} $data fromArray() checks it */
            $data = self::arrays(self::of($result));

            return CallToolResult::fromArray($data);
        } catch (\Throwable) {
            return new CallToolResult([]);
        }
    }

    /** A tree with its objects as associative arrays, the way mcp/sdk reads decoded JSON. */
    private static function arrays(mixed $tree): mixed
    {
        if ($tree instanceof \stdClass) {
            $tree = get_object_vars($tree);
        }

        return is_array($tree) ? array_map(self::arrays(...), $tree) : $tree;
    }
}
