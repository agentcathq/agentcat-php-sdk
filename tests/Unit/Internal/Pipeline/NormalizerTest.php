<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Pipeline\Normalizer;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Samples\BackedStatus;
use AgentCat\Tests\Support\Samples\PlainThing;
use AgentCat\Tests\Support\Samples\SerializableThing;
use AgentCat\Tests\Support\Samples\UnitStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed}> */
    public static function scalarProvider(): iterable
    {
        yield 'nan' => [NAN, '[NaN]'];
        yield 'infinity' => [INF, '[Infinity]'];
        yield 'negative infinity' => [-INF, '[-Infinity]'];
        yield 'date' => [new \DateTimeImmutable('2025-01-15T12:00:00Z'), '2025-01-15T12:00:00.000Z'];
        yield 'int' => [42, 42];
        yield 'zero' => [0, 0];
        yield 'float' => [-3.14, -3.14];
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'null' => [null, null];
        yield 'int max' => [PHP_INT_MAX, PHP_INT_MAX];
    }

    #[Group('ac-05-T5')]
    #[DataProvider('scalarProvider')]
    public function testScalars(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, Normalizer::normalize($input));
    }

    #[Group('ac-05-T5')]
    public function testFunctions(): void
    {
        $this->assertSame('[Function: <anonymous>]', Normalizer::normalize(static function (): void {}));
        $this->assertSame('[Function: <anonymous>]', Normalizer::normalize(static fn(): int => 1));
        $this->assertSame('[Function: strlen]', Normalizer::normalize(strlen(...)));
    }

    #[Group('ac-05-T1')]
    public function testStringLimit(): void
    {
        $long = Normalizer::normalize(str_repeat('x', 33000));
        $this->assertIsString($long);
        $this->assertSame(32771, strlen($long));
        $this->assertStringEndsWith('...', $long);
        $this->assertSame(str_repeat('x', 32768), Normalizer::normalize(str_repeat('x', 32768)));
        $this->assertSame('short', Normalizer::normalize('short'));
        $this->assertSame('', Normalizer::normalize(''));
    }

    public function testMultibyteStringBelowLimitInUnitsIsKept(): void
    {
        $text = str_repeat('é', 32768); // 65536 bytes, 32768 units
        $this->assertSame($text, Normalizer::normalize($text));
        $cut = Normalizer::normalize($text . 'é');
        $this->assertSame(str_repeat('é', 32768) . '...', $cut);
    }

    private function nested(int $levels): \stdClass
    {
        $root = new \stdClass();
        $node = $root;
        for ($i = 0; $i < $levels; ++$i) {
            $node->nested = new \stdClass();
            $node = $node->nested;
        }

        return $root;
    }

    #[Group('ac-05-T6')]
    public function testObjectDepth(): void
    {
        $r = Normalizer::normalize($this->nested(15), 5);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertInstanceOf(\stdClass::class, JsonAssert::at($r, 'nested', 'nested', 'nested', 'nested'));
        $this->assertSame('[Object]', JsonAssert::at($r, 'nested', 'nested', 'nested', 'nested', 'nested'));
    }

    #[Group('ac-05-T6')]
    public function testDefaultDepthKeepsTenLevels(): void
    {
        $r = Normalizer::normalize($this->nested(15));
        $node = $r;
        for ($i = 0; $i < 10; ++$i) {
            $this->assertInstanceOf(\stdClass::class, $node);
            $node = $node->nested;
        }
        $this->assertSame('[Object]', $node);
    }

    #[Group('ac-05-T6')]
    public function testListDepth(): void
    {
        $r = Normalizer::normalize([[[[[[1]]]]]], 3);
        $this->assertIsArray($r);
        $this->assertIsArray(JsonAssert::at($r, 0));
        $this->assertIsArray(JsonAssert::at($r, 0, 0));
        $this->assertSame('[Array]', JsonAssert::at($r, 0, 0, 0));
    }

    #[Group('ac-05-T6')]
    public function testDepthZero(): void
    {
        $this->assertSame('[Object]', Normalizer::normalize(Json::decode('{"a":1}'), 0));
        $this->assertSame('[Array]', Normalizer::normalize([1], 0));
        $this->assertSame('[Object]', Normalizer::normalize(['a' => 1], 0));
        $this->assertSame('x', Normalizer::normalize('x', 0));
        $this->assertSame(1, Normalizer::normalize(1, 0));
    }

    #[Group('ac-05-T7')]
    public function testObjectBreadth(): void
    {
        $input = [];
        for ($i = 0; $i < 150; ++$i) {
            $input['k' . $i] = $i;
        }
        $r = Normalizer::normalize($input, 10, 5);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertCount(6, get_object_vars($r));
        $this->assertSame('[MaxProperties ~]', $r->{'...'});
        $this->assertSame(4, $r->k4);
    }

    #[Group('ac-05-T7')]
    public function testListBreadth(): void
    {
        $r = Normalizer::normalize(range(0, 149), 10, 5);
        $this->assertSame([0, 1, 2, 3, 4, '[MaxProperties ~]'], $r);
    }

    #[Group('ac-05-T7')]
    public function testExactBreadthIsUnchanged(): void
    {
        $this->assertSame([0, 1, 2, 3, 4], Normalizer::normalize(range(0, 4), 10, 5));
        $r = Normalizer::normalize(['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5], 10, 5);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertCount(5, get_object_vars($r));
        $this->assertFalse(property_exists($r, '...'));
    }

    #[Group('ac-05-T7')]
    public function testContentBlocksUseDefaultBreadth(): void
    {
        $blocks = [];
        for ($i = 0; $i < 150; ++$i) {
            $blocks[] = Json::decode('{"type":"text","text":"b' . $i . '"}');
        }
        $r = Normalizer::normalize((object) ['content' => $blocks]);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertCount(101, (array) JsonAssert::at($r, 'content'));
        $this->assertSame('[MaxProperties ~]', JsonAssert::at($r, 'content', 100));
    }

    #[Group('ac-05-T8')]
    public function testSelfReference(): void
    {
        $o = new \stdClass();
        $o->self = $o;
        $r = Normalizer::normalize($o);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertSame('[Circular ~]', $r->self);
    }

    #[Group('ac-05-T8')]
    public function testCircularThroughList(): void
    {
        $o = new \stdClass();
        $o->items = [$o];
        $r = Normalizer::normalize($o);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertSame('[Circular ~]', JsonAssert::at($r, 'items', 0));
    }

    #[Group('ac-05-T8')]
    public function testDeepBackEdge(): void
    {
        $root = new \stdClass();
        $a = new \stdClass();
        $b = new \stdClass();
        $root->a = $a;
        $a->b = $b;
        $b->root = $root;
        $r = Normalizer::normalize($root);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertSame('[Circular ~]', JsonAssert::at($r, 'a', 'b', 'root'));
    }

    #[Group('ac-05-T8')]
    public function testSharedObjectIsExpandedTwice(): void
    {
        $shared = new \stdClass();
        $shared->v = 1;
        $r = Normalizer::normalize(['x' => $shared, 'y' => $shared]);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertSame(1, JsonAssert::at($r, 'x', 'v'));
        $this->assertSame(1, JsonAssert::at($r, 'y', 'v'));
    }

    #[Group('ac-05-T8')]
    public function testReferenceCyclicArrayStopsAtDepthLimit(): void
    {
        $a = ['k' => 1];
        $a['self'] = &$a;
        $r = Normalizer::normalize($a);
        $this->assertInstanceOf(\stdClass::class, $r);
        $node = $r;
        for ($i = 0; $i < 9; ++$i) {
            $node = $node->self;
            $this->assertInstanceOf(\stdClass::class, $node);
        }
        $this->assertSame('[Object]', $node->self);
    }

    #[Group('ac-05-T17')]
    public function testEnums(): void
    {
        $this->assertSame('[Enum: AgentCat\Tests\Support\Samples\UnitStatus::Active]', Normalizer::normalize(UnitStatus::Active));
        $this->assertSame('active', Normalizer::normalize(BackedStatus::Active));
    }

    #[Group('ac-05-T17')]
    public function testResource(): void
    {
        $stream = fopen('php://memory', 'rb');
        $this->assertIsResource($stream);
        $this->assertSame('[Resource: stream]', Normalizer::normalize($stream));
        fclose($stream);
    }

    #[Group('ac-05-T17')]
    public function testJsonSerializable(): void
    {
        $r = Normalizer::normalize(new SerializableThing());
        $this->assertEquals((object) ['kind' => 'thing', 'size' => 2], $r);
        $this->assertSame('[Circular ~]', Normalizer::normalize(new SerializableThing(returnSelf: true)));
    }

    #[Group('ac-05-T17')]
    public function testJsonSerializableExpansionUsesNoDepth(): void
    {
        $r = Normalizer::normalize(new SerializableThing(), 1);
        $this->assertEquals((object) ['kind' => 'thing', 'size' => 2], $r);
    }

    #[Group('ac-05-T17')]
    public function testJsonSerializableExceptionPropagates(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot serialize');
        Normalizer::normalize(new SerializableThing(throw: true));
    }

    #[Group('ac-05-T17')]
    public function testPlainObjectKeepsPublicPropertiesOnly(): void
    {
        $r = Normalizer::normalize(new PlainThing());
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertEquals((object) ['visible' => 'v', 'next' => null], $r);
    }

    #[Group('ac-05-T17')]
    public function testArraysBecomeObjectsOrLists(): void
    {
        $this->assertEquals((object) ['a' => 1], Normalizer::normalize(['a' => 1]));
        $this->assertInstanceOf(\stdClass::class, Normalizer::normalize(['a' => 1]));
        $r = Normalizer::normalize([1 => 'x']);
        $this->assertInstanceOf(\stdClass::class, $r);
        $this->assertSame('{"1":"x"}', json_encode($r));
        $this->assertSame([], Normalizer::normalize([]));
        $this->assertSame([1, 'a'], Normalizer::normalize([1, 'a']));
    }

    #[Group('ac-05-T17')]
    public function testNoPhpMapEscapesInNestedOutput(): void
    {
        $r = Normalizer::normalize(['a' => [1 => ['b' => 2]], 'l' => [['m' => 1]]]);
        $this->assertSame('{"a":{"1":{"b":2}},"l":[{"m":1}]}', json_encode($r));
    }

    public function testNestedValuesAreVisited(): void
    {
        $r = Normalizer::normalize(['x' => [NAN, static fn(): int => 1]]);
        $this->assertSame('{"x":["[NaN]","[Function: <anonymous>]"]}', json_encode($r));
    }

    #[Group('ac-05-T8')]
    public function testSharedJsonSerializableIsExpandedTwice(): void
    {
        $thing = new SerializableThing();
        $r = Normalizer::normalize([$thing, $thing]);
        $this->assertEquals([(object) ['kind' => 'thing', 'size' => 2], (object) ['kind' => 'thing', 'size' => 2]], $r);
    }

    #[Group('ac-05-T1')]
    public function testMultibyteStringLimitCountsUnits(): void
    {
        $this->assertSame(str_repeat('é', 5), Normalizer::normalize(str_repeat('é', 5), 10, 100, 5));
        $this->assertSame(str_repeat('é', 5) . '...', Normalizer::normalize(str_repeat('é', 6), 10, 100, 5));
        $this->assertSame('abcde', Normalizer::normalize('abcde', 10, 100, 5));
        $this->assertSame('abcde...', Normalizer::normalize('abcdef', 10, 100, 5));
    }
}
