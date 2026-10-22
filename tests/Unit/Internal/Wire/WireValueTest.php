<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Wire;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Wire\WireValue;
use AgentCat\Tests\Support\Samples\BackedStatus;
use AgentCat\Tests\Support\Samples\PlainThing;
use AgentCat\Tests\Support\Samples\SerializableThing;
use AgentCat\Tests\Support\Samples\UnitStatus;
use AgentCat\Tests\Support\Samples\VisibilityThing;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;

final class WireValueTest extends TestCase
{
    public function testScalarsPassThrough(): void
    {
        foreach ([null, true, 1, 'x', 1.5] as $value) {
            $this->assertSame($value, WireValue::from($value));
        }
    }

    public function testNonFiniteFloatsBecomeNull(): void
    {
        $this->assertNull(WireValue::from(NAN));
        $this->assertNull(WireValue::from(INF));
        $this->assertNull(WireValue::from(-INF));
    }

    public function testClosures(): void
    {
        $closure = static fn(): int => 1;

        $this->assertNull(WireValue::from($closure));
        $this->assertSame([1, null, 2], WireValue::from([1, $closure, 2]));
        $this->assertEquals((object) ['a' => 1], WireValue::from(['a' => 1, 'f' => $closure]));
    }

    public function testNonPublicKeysOfAnArrayCastAreSkipped(): void
    {
        $this->assertEquals((object) ['name' => 'n'], WireValue::from((array) new VisibilityThing()));
        $this->assertEquals((object) ['name' => 'n'], WireValue::from((object) (array) new VisibilityThing()));
    }

    public function testArrayShapes(): void
    {
        $this->assertEquals((object) ['a' => 1], WireValue::from(['a' => 1]));
        $this->assertSame('{"1":"x","2":"y"}', Json::encodeJs(WireValue::from([1 => 'x', 2 => 'y'])));
        $this->assertSame('["x"]', Json::encodeJs(WireValue::from(['0' => 'x'])));
        $this->assertSame([], WireValue::from([]));
    }

    public function testDatesEnumsAndResources(): void
    {
        $date = new \DateTimeImmutable('2026-10-16 14:00:00.123456', new \DateTimeZone('Europe/Paris'));
        $stream = fopen('php://memory', 'r');
        $this->assertIsResource($stream);

        $this->assertSame('2026-10-16T12:00:00.123Z', WireValue::from($date));
        $this->assertSame('active', WireValue::from(BackedStatus::Active));
        $this->assertSame('[Enum: AgentCat\Tests\Support\Samples\UnitStatus::Active]', WireValue::from(UnitStatus::Active));
        $this->assertSame('[Resource: stream]', WireValue::from($stream));
        fclose($stream);
        $this->assertSame('[Resource: closed]', WireValue::from($stream));
    }

    public function testJsonSerializable(): void
    {
        $this->assertEquals((object) ['kind' => 'thing', 'size' => 2], WireValue::from(new SerializableThing()));

        $e = Thrown::by(\UnexpectedValueException::class, fn(): mixed => WireValue::from(new SerializableThing(returnSelf: true)));
        $this->assertStringContainsString('Converting circular structure to JSON', $e->getMessage());
    }

    public function testJsonSerializableThrowPropagates(): void
    {
        $e = Thrown::by(\RuntimeException::class, fn(): mixed => WireValue::from(new SerializableThing(throw: true)));
        $this->assertStringContainsString('cannot serialize', $e->getMessage());
    }

    public function testPlainObjectExposesPublicProperties(): void
    {
        $this->assertEquals((object) ['visible' => 'v', 'next' => null], WireValue::from(new PlainThing()));
    }

    public function testCircularObject(): void
    {
        $object = new \stdClass();
        $object->self = $object;

        $e = Thrown::by(\UnexpectedValueException::class, fn(): mixed => WireValue::from($object));
        $this->assertStringContainsString('Converting circular structure to JSON', $e->getMessage());
    }

    public function testSharedObjectIsExpandedTwice(): void
    {
        $shared = new \stdClass();
        $shared->v = 1;
        $result = WireValue::from(['a' => $shared, 'b' => $shared]);

        $this->assertEquals((object) ['a' => (object) ['v' => 1], 'b' => (object) ['v' => 1]], $result);
    }

    public function testSharedObjectInsideJsonSerializableCycleIsDetectedOnlyAlongThePath(): void
    {
        $leaf = new PlainThing();
        $leaf->next = new PlainThing();
        $leaf->next->next = null;

        $this->assertEquals(
            (object) ['visible' => 'v', 'next' => (object) ['visible' => 'v', 'next' => null]],
            WireValue::from($leaf),
        );
    }

    public function testDepthCap(): void
    {
        $array = [];
        $array['self'] = &$array;

        $e = Thrown::by(\UnexpectedValueException::class, fn(): mixed => WireValue::from($array));
        $this->assertStringContainsString('Maximum nesting depth of 512 exceeded', $e->getMessage());
    }

    public function testDepthExactlyAtTheCapIsAccepted(): void
    {
        $value = 1;
        for ($i = 0; $i < WireValue::MAX_DEPTH; ++$i) {
            $value = [$value];
        }

        $this->assertIsArray(WireValue::from($value));

        $this->expectException(\UnexpectedValueException::class);
        WireValue::from([$value]);
    }

    public function testObjectNestingIsCappedAtTheSameDepth(): void
    {
        $value = 1;
        for ($i = 0; $i < WireValue::MAX_DEPTH; ++$i) {
            $value = (object) ['k' => $value];
        }

        $this->assertInstanceOf(\stdClass::class, WireValue::from($value));

        $this->expectException(\UnexpectedValueException::class);
        WireValue::from((object) ['k' => $value]);
    }
}
