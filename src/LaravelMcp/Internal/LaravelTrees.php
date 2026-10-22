<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Json\JsonTree;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Turns laravel/mcp answers (associative arrays, `Arrayable` and `\JsonSerializable` values) into the SDK's JSON
 * trees, and JSON trees back into laravel/mcp's own arrays.
 *
 * @internal
 */
final class LaravelTrees
{
    /**
     * The JSON tree list of a listing's `result.tools`, or null when the answer is not a response whose result holds
     * a JSON list of tools.
     *
     * @return list<mixed>|null
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function tools(mixed $response): ?array
    {
        if (!$response instanceof JsonRpcResponse) {
            return null;
        }
        $result = $response->content['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }
        $tools = JsonTree::of($result['tools'] ?? null);

        return is_array($tools) && array_is_list($tools) ? $tools : null;
    }

    /**
     * The tree of a response's tool result (`JsonTree::result()`): laravel/mcp's arrays cannot tell `{}` from `[]`,
     * so an empty-list `structuredContent` is `{}`.
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public static function result(JsonRpcResponse $response): mixed
    {
        return JsonTree::result($response->content['result'] ?? null);
    }

    /**
     * laravel/mcp's own shape for a JSON tree: associative arrays, which its test helpers index, except objects that
     * an array would change on the wire: `{}` and objects whose keys are 0, 1, … stay `\stdClass`.
     */
    public static function native(mixed $tree): mixed
    {
        if (is_array($tree)) {
            return array_map(self::native(...), $tree);
        }
        if (!$tree instanceof \stdClass) {
            return $tree;
        }
        $fields = array_map(self::native(...), get_object_vars($tree));
        if ($fields === [] || array_is_list($fields)) {
            return (object) $fields;
        }

        return $fields;
    }

    /**
     * A tool result for laravel/mcp: an array at the top, and `_meta` an array too, because `Server::send()` writes
     * `result['_meta'][…]` into it.
     *
     * @return array<string, mixed>
     */
    public static function nativeResult(\stdClass $tree): array
    {
        $result = [];
        foreach (get_object_vars($tree) as $key => $value) {
            $result[(string) $key] = self::native($value); // the cast types the key: PHP keeps a numeric key an int
        }
        if (isset($result['_meta']) && $result['_meta'] instanceof \stdClass) {
            $result['_meta'] = get_object_vars($result['_meta']);
        }

        return $result;
    }
}
