<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Json;

use AgentCat\Internal\Json\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testDecodeKeepsObjectsAndNumericKeys(): void
    {
        $value = Json::decode('{"a":{},"b":[],"0":1}');

        $this->assertInstanceOf(\stdClass::class, $value);
        $this->assertInstanceOf(\stdClass::class, $value->a);
        $this->assertSame([], $value->b);
        $this->assertTrue(property_exists($value, '0'));
        $this->assertSame('{"a":{},"b":[],"0":1}', Json::encodeJs($value));
    }

    public function testDecodeThrowsOnInvalidJson(): void
    {
        $this->expectException(\JsonException::class);
        Json::decode('{nope');
    }

    public function testEncodeUsesJsStyleOutput(): void
    {
        $this->assertSame('{"q":"hello/é"}', Json::encodeJs(['q' => 'hello/é']));
        $this->assertSame("\"\u{2028}\"", Json::encodeJs("\u{2028}"));
        $this->assertSame('1', Json::encodeJs(1.0));
    }

    public function testEncodeReturnsNullOnFailure(): void
    {
        $this->assertNull(Json::encodeJs("\xB1\x31"));
        $this->assertNull(Json::encodeJs(NAN));
    }

    public function testDeepCopyIsIndependent(): void
    {
        $original = Json::decode('{"a":{"b":[{"c":1}]},"d":"x"}');
        $copy = Json::deepCopy($original);

        $this->assertEquals($original, $copy);
        $this->assertInstanceOf(\stdClass::class, $copy);
        $this->assertInstanceOf(\stdClass::class, $copy->a);
        $this->assertIsArray($copy->a->b);
        $this->assertArrayHasKey(0, $copy->a->b);
        $this->assertInstanceOf(\stdClass::class, $copy->a->b[0]);
        $copy->a->b[0]->c = 2;
        $copy->a->extra = true;

        $this->assertInstanceOf(\stdClass::class, $original);
        $this->assertSame('{"a":{"b":[{"c":1}]},"d":"x"}', Json::encodeJs($original));
    }

    public function testDeepCopyKeepsNumericKeys(): void
    {
        $copy = Json::deepCopy(Json::decode('{"0":{"1":2}}'));

        $this->assertSame('{"0":{"1":2}}', Json::encodeJs($copy));
    }

    public function testDecodeDepthLimitIsPhpDefault(): void
    {
        $this->assertIsArray(Json::decode(str_repeat('[', 511) . str_repeat(']', 511)));

        $this->expectException(\JsonException::class);
        Json::decode(str_repeat('[', 512) . str_repeat(']', 512));
    }

    public function testDeepCopyReturnsScalarsAsIs(): void
    {
        $this->assertSame(5, Json::deepCopy(5));
        $this->assertSame('s', Json::deepCopy('s'));
        $this->assertNull(Json::deepCopy(null));
    }
}
