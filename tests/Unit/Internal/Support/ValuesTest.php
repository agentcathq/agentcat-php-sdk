<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\Values;
use AgentCat\Tests\Support\Samples\PlainThing;
use AgentCat\Tests\Support\Samples\UnitStatus;
use AgentCat\Tests\Support\Samples\VisibilityThing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValuesTest extends TestCase
{
    public function testIsoDate(): void
    {
        $date = new \DateTimeImmutable('2026-10-16 14:00:00.123456', new \DateTimeZone('Europe/Paris'));

        $this->assertSame('2026-10-16T12:00:00.123Z', Values::isoDate($date));
        $this->assertSame('2026-10-16T12:00:00.123Z', Values::isoDate(\DateTime::createFromImmutable($date)));
    }

    public function testEpochMillisFloorsBeforeTheEpochToo(): void
    {
        $this->assertSame(1749988805123, Values::epochMillis(new \DateTimeImmutable('2025-06-15T12:00:05.123Z')));
        $this->assertSame(-500, Values::epochMillis(new \DateTimeImmutable('1969-12-31T23:59:59.500Z')));
        $this->assertSame(-1000, Values::epochMillis(new \DateTimeImmutable('1969-12-31T23:59:59.000Z')));
    }

    public function testEnumMarker(): void
    {
        $this->assertSame('[Enum: AgentCat\Tests\Support\Samples\UnitStatus::Active]', Values::enumMarker(UnitStatus::Active));
    }

    public function testResourceMarker(): void
    {
        $stream = fopen('php://memory', 'r');
        $this->assertIsResource($stream);
        $this->assertSame('[Resource: stream]', Values::resourceMarker($stream));
        fclose($stream);
        $this->assertSame('[Resource: closed]', Values::resourceMarker($stream));
    }

    public function testEntriesOfArrayAndObject(): void
    {
        $this->assertSame(['a' => 1], Values::entries(['a' => 1]));
        $this->assertSame(['visible' => 'v', 'next' => null], Values::entries(new PlainThing()));
    }

    /** `(array) $object` keys of protected and private properties start with a NUL byte; they are not public. */
    public function testEntriesSkipKeysThatStartWithANulByte(): void
    {
        $this->assertSame(['name' => 'n'], Values::entries((array) new VisibilityThing()));
        $this->assertSame(['name' => 'n'], Values::entries((object) (array) new VisibilityThing()));
        $this->assertSame([0 => 'a', '' => 'b', "k\0" => 'c', 'z' => 'd'], Values::entries([0 => 'a', '' => 'b', "\0" => 'x', "k\0" => 'c', "\0k" => 'y', 'z' => 'd']));
        $this->assertSame([1, 2], Values::entries([1, 2]));
    }

    public function testHasAndGet(): void
    {
        $object = new \stdClass();
        $object->k = null;

        $this->assertTrue(Values::has($object, 'k'));
        $this->assertNull(Values::get($object, 'k'));
        $this->assertFalse(Values::has($object, 'z'));
        $this->assertTrue(Values::has(['a' => 0], 'a'));
        $this->assertSame(0, Values::get(['a' => 0], 'a'));
        $this->assertNull(Values::get(['a' => 0], 'z'));
        $this->assertFalse(Values::has([], 'a'));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function objectLike(): iterable
    {
        yield 'empty array' => [[], true];
        yield 'map' => [['a' => 1], true];
        yield 'stdClass' => [new \stdClass(), true];
        yield 'plain' => [new PlainThing(), true];
        yield 'null' => [null, false];
        yield 'string' => ['x', false];
        yield 'int' => [1, false];
        yield 'closure' => [static fn(): int => 1, false];
        yield 'enum' => [UnitStatus::Active, false];
    }

    #[DataProvider('objectLike')]
    public function testIsObjectLike(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Values::isObjectLike($value));
    }
}
