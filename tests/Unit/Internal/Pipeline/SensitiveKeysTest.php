<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Pipeline\SensitiveKeys;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Samples\WrappedValue;
use PHPUnit\Framework\TestCase;

final class SensitiveKeysTest extends TestCase
{
    private const string VECTORS = __DIR__ . '/../../../Fixtures/sensitive-keys/vectors.json';

    public function testEveryKeyCaseOfTheVectors(): void
    {
        $vectors = JsonAssert::object(Json::decode((string) file_get_contents(self::VECTORS)));
        $this->assertSame(SensitiveKeys::MASK, $vectors->mask);
        $cases = JsonAssert::list($vectors->keys);
        $this->assertCount(233, $cases);
        foreach ($cases as $case) {
            $case = JsonAssert::object($case);
            $this->assertIsString($case->key);
            $this->assertSame($case->sensitive, SensitiveKeys::isSensitiveKey($case->key), $case->key);
        }
    }

    public function testEveryTreeCaseOfTheVectors(): void
    {
        $vectors = JsonAssert::object(Json::decode((string) file_get_contents(self::VECTORS)));
        $cases = JsonAssert::list($vectors->trees);
        $this->assertCount(17, $cases);
        foreach ($cases as $case) {
            $case = JsonAssert::object($case);
            $this->assertIsString($case->name);
            $before = json_encode($case->input, JSON_THROW_ON_ERROR);

            $masked = SensitiveKeys::maskSensitiveKeys($case->input);

            JsonAssert::assertSameJson(json_encode($case->expected, JSON_THROW_ON_ERROR), json_encode($masked, JSON_THROW_ON_ERROR), $case->name);
            $this->assertSame($before, json_encode($case->input, JSON_THROW_ON_ERROR), $case->name . ': the input is unchanged');
        }
    }

    public function testPhpArraysObjectsAndCycles(): void
    {
        $cycle = new \stdClass();
        $cycle->name = 'loop';
        $cycle->self = $cycle;
        $input = [
            'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
            'serialized' => new WrappedValue(['api_key' => 'k', 'page' => 2]),
            'cycle' => $cycle,
            'properties' => ['token' => new WrappedValue('abc')],
            7 => ['token' => 't'],
        ];

        $masked = SensitiveKeys::maskSensitiveKeys($input);

        $this->assertSame(['Authorization' => '[redacted]', 'Accept' => 'application/json'], JsonAssert::at($masked, 'headers'));
        JsonAssert::assertSameJson('{"api_key":"[redacted]","page":2}', json_encode(JsonAssert::at($masked, 'serialized'), JSON_THROW_ON_ERROR));
        JsonAssert::assertSameJson('{"name":"loop","self":"[redacted]"}', json_encode(JsonAssert::at($masked, 'cycle'), JSON_THROW_ON_ERROR));
        $this->assertSame(['token' => '[redacted]'], JsonAssert::at($masked, 7));
        $this->assertSame(['token' => '[redacted]'], JsonAssert::at($masked, 'properties'));
        $this->assertSame($cycle, $cycle->self);
    }
}
