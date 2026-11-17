<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Json\Json;
use PHPUnit\Framework\Assert;

final class JsonAssert
{
    /** Decodes both with objects (so {} and [] differ), sorts object keys recursively, compares exact encodings. */
    public static function assertSameJson(string $expectedJson, string $actualJson, string $message = ''): void
    {
        Assert::assertSame(self::canonical($expectedJson), self::canonical($actualJson), $message);
    }

    /** Reads a nested value from decoded JSON: object property names and list indexes. */
    public static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $step) {
            if ($value instanceof \stdClass) {
                $value = $value->{(string) $step};
            } else {
                Assert::assertIsArray($value);
                Assert::assertArrayHasKey($step, $value);
                $value = $value[$step];
            }
        }

        return $value;
    }

    /**
     * A decoded JSON list.
     *
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        Assert::assertIsList($value);

        return $value;
    }

    /**
     * The member `$key` of each object of a decoded JSON list; null where an item has none.
     *
     * @return list<mixed>
     */
    public static function column(mixed $list, string $key): array
    {
        return array_map(static fn(mixed $item): mixed => $item instanceof \stdClass ? $item->{$key} ?? null : null, self::list($list));
    }

    /** A decoded JSON object. */
    public static function object(mixed $value): \stdClass
    {
        Assert::assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    /** The member `$key` of a decoded JSON object, or null when it has none. */
    public static function optional(mixed $object, string $key): mixed
    {
        return self::object($object)->{$key} ?? null;
    }

    /**
     * The member names of a decoded JSON object, in order.
     *
     * @return list<string>
     */
    public static function keys(mixed $object): array
    {
        Assert::assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }

    private static function canonical(string $json): string
    {
        return Json::encodeJs(self::sorted(Json::decode($json))) ?? 'unencodable';
    }

    private static function sorted(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $entries = get_object_vars($value);
            ksort($entries, SORT_STRING);
            $sorted = new \stdClass();
            foreach ($entries as $key => $item) {
                $sorted->{(string) $key} = self::sorted($item);
            }

            return $sorted;
        }

        return is_array($value) ? array_map(self::sorted(...), $value) : $value;
    }
}
