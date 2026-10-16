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
