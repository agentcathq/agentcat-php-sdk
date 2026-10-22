<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ExportJsonTest extends TestCase
{
    #[Group('ac-06-64')]
    public function testFloatsAreExactWhateverSerializePrecisionSays(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $this->assertSame('{"t":1749988805.123,"l":0.15}', ExportJson::encode(['t' => 1749988805.123, 'l' => 150 / 1000]));
            $this->assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function iniFunctions(): iterable
    {
        yield 'ini_set' => ['ini_set'];
        yield 'ini_get' => ['ini_get'];
    }

    /** A host can disable ini_set() or ini_get(): floats then follow php.ini's serialize_precision, and nothing fails. */
    #[DataProvider('iniFunctions')]
    public function testWithoutAnIniFunctionFloatsFollowSerializePrecision(string $missing): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            $this->assertSame('{"l":0.14999999999999999}', ExportJson::encode(['l' => 150 / 1000], static fn(string $function): bool => $function !== $missing));
            $this->assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    public function testBothIniFunctionsAreChecked(): void
    {
        $asked = new \ArrayObject();

        ExportJson::encode(1.5, static function (string $function) use ($asked): bool {
            $asked[] = $function;

            return true;
        });

        $this->assertSame(['ini_set', 'ini_get'], $asked->getArrayCopy());
    }

    public function testSerializePrecisionIsRestoredAfterAFailure(): void
    {
        $before = ini_get('serialize_precision');
        ini_set('serialize_precision', '14');

        try {
            $loop = [];
            $loop['self'] = &$loop;
            Thrown::by(\UnexpectedValueException::class, static fn(): string => ExportJson::encode($loop));
            $this->assertSame('14', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    }

    public function testJsonStringifyRulesForUnencodableValues(): void
    {
        $encoded = ExportJson::encode(['u' => "caf\xE9", 'n' => NAN, 'i' => INF, 'f' => static fn(): int => 1, 'l' => [static fn(): int => 1]]);

        $this->assertSame("{\"u\":\"caf\u{FFFD}\",\"n\":null,\"i\":null,\"l\":[null]}", $encoded);
    }

    public function testNothingIsEscapedThatJsonStringifyLeavesAlone(): void
    {
        $this->assertSame("{\"a\":\"/x\",\"b\":\"é\",\"c\":\"\u{2028}\"}", ExportJson::encode(['a' => '/x', 'b' => 'é', 'c' => "\u{2028}"]));
        $this->assertSame('1', ExportJson::encode(1.0));
    }

    public function testASelfReferencingValueThrows(): void
    {
        $node = new \stdClass();
        $node->self = $node;

        Thrown::by(\UnexpectedValueException::class, static fn(): string => ExportJson::encode($node));
    }

    public function testAnEmptyArrayInAMapPositionBecomesAnEmptyObject(): void
    {
        $map = ExportJson::map([]);

        $this->assertInstanceOf(\stdClass::class, $map);
        $this->assertSame([], get_object_vars($map));
        $this->assertSame('{}', ExportJson::encode($map));
        $this->assertSame(['a' => 1], ExportJson::map(['a' => 1]));
        $this->assertNull(ExportJson::map(null));
        $this->assertSame('x', ExportJson::map('x'));
        $this->assertSame(0, ExportJson::map(0));
    }
}
