<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function truthyProvider(): iterable
    {
        yield 'null' => [null, false];
        yield 'false' => [false, false];
        yield 'zero' => [0, false];
        yield 'float zero' => [0.0, false];
        yield 'empty string' => ['', false];
        yield 'nan' => [NAN, false];
        yield 'string zero' => ['0', true];
        yield 'empty list' => [[], true];
        yield 'empty object' => [new \stdClass(), true];
        yield 'minus one' => [-1, true];
        yield 'true' => [true, true];
        yield 'text' => ['a', true];
        yield 'float' => [0.5, true];
    }

    #[DataProvider('truthyProvider')]
    public function testTruthy(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Js::truthy($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whitespaceProvider(): iterable
    {
        $codepoints = [0x09, 0x0A, 0x0B, 0x0C, 0x0D, 0x20, 0xA0, 0x1680, 0x2000, 0x2005, 0x200A, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000, 0xFEFF];
        foreach ($codepoints as $codepoint) {
            yield sprintf('U+%04X', $codepoint) => [mb_chr($codepoint, 'UTF-8')];
        }
    }

    #[DataProvider('whitespaceProvider')]
    public function testTrimRemovesJsWhitespaceFromBothEnds(string $space): void
    {
        $this->assertSame('x', Js::trim($space . $space . 'x' . $space . $space));
        $this->assertSame('', Js::trim($space));
    }

    public function testTrimKeepsNonWhitespace(): void
    {
        $this->assertSame("\u{0085}a\u{0085}", Js::trim("\u{0085}a\u{0085}"));
        $this->assertSame("\0a\0", Js::trim("\0a\0"));
        $this->assertSame('a b', Js::trim(' a b '));
        $this->assertSame('start', Js::trim('  start  '));
        $this->assertSame('START', Js::trim(" START\t"));
    }

    public function testTrimReturnsInvalidUtf8Unchanged(): void
    {
        $this->assertSame(" \xB1\x31 ", Js::trim(" \xB1\x31 "));
    }

    public function testUtf16Length(): void
    {
        $this->assertSame(3, Js::utf16Length('abc'));
        $this->assertSame(1, Js::utf16Length('é'));
        $this->assertSame(2, Js::utf16Length('😀'));
        $this->assertSame(4, Js::utf16Length('a😀b'));
        $this->assertSame(0, Js::utf16Length(''));
    }

    public function testUtf16SliceCutsAscii(): void
    {
        $this->assertSame(str_repeat('a', 200), Js::utf16Slice(str_repeat('a', 500), 200));
    }

    public function testUtf16SliceKeepsShortStringUnchanged(): void
    {
        $this->assertSame('abc', Js::utf16Slice('abc', 3));
        $this->assertSame('abc', Js::utf16Slice('abc', 10));
    }

    public function testUtf16SliceDropsDanglingHighSurrogate(): void
    {
        $this->assertSame('ab', Js::utf16Slice('ab😀', 3));
        $this->assertSame('ab😀', Js::utf16Slice('ab😀', 4));
        $this->assertSame('ab😀', Js::utf16Slice('ab😀c', 4));
        $this->assertSame('', Js::utf16Slice('😀', 1));
        $this->assertSame('', Js::utf16Slice('abc', 0));
        $this->assertSame('', Js::utf16Slice('abc', -1));
        $this->assertSame('', Js::utf16Slice('', 5));
        $this->assertSame("a\u{10000}", Js::utf16Slice("a\u{10000}b", 3));
        $this->assertSame('a', Js::utf16Slice("a\u{10000}b", 2));
        $this->assertSame("a\u{10FFFF}", Js::utf16Slice("a\u{10FFFF}b", 3));
        $this->assertSame('a', Js::utf16Slice("a\u{10FFFF}b", 2));
        $this->assertSame("\u{D7FF}", Js::utf16Slice("\u{D7FF}b", 1));
        $this->assertSame("\u{E000}", Js::utf16Slice("\u{E000}b", 1));
    }

    public function testUtf16SliceKeepsLowSurrogateBoundaryValid(): void
    {
        $result = Js::utf16Slice('😀😀😀', 5);
        $this->assertSame('😀😀', $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function stringProvider(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [5, '5'];
        yield 'whole float' => [5.0, '5'];
        yield 'negative zero' => [-0.0, '0'];
        yield 'zero' => [0.0, '0'];
        yield 'fraction' => [1.5, '1.5'];
        yield 'rounding' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'nan' => [NAN, 'NaN'];
        yield 'infinity' => [INF, 'Infinity'];
        yield 'negative infinity' => [-INF, '-Infinity'];
        yield 'string' => ['x', 'x'];
        yield 'list with null' => [[1, null, 'a'], '1,,a'];
        yield 'nested list' => [[[1, 2], 3], '1,2,3'];
        yield 'empty object' => [new \stdClass(), '[object Object]'];
        yield 'map' => [['k' => 'v'], '[object Object]'];
        yield 'large whole float' => [1e20, '100000000000000000000'];
        yield 'huge float' => [1e21, '1e+21'];
        yield 'half' => [0.5, '0.5'];
        yield 'twentieth' => [0.05, '0.05'];
        yield 'negative fraction' => [-0.25, '-0.25'];
        yield 'huge fraction' => [1.5e300, '1.5e+300'];
        yield 'small float' => [0.00001, '0.00001'];
        yield 'smallest plain float' => [0.000001, '0.000001'];
        yield 'tiny float' => [1e-7, '1e-7'];
        yield 'tiny fraction' => [-1.5e-7, '-1.5e-7'];
        yield 'fraction above one' => [123.456, '123.456'];
        yield 'negative whole float' => [-3.0, '-3'];
    }

    #[DataProvider('stringProvider')]
    public function testString(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Js::string($value));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function sliceEndProvider(): iterable
    {
        yield 'tail' => ['abcdef', 3, 'def'];
        yield 'lone low surrogate dropped' => ['😀ab', 3, 'ab'];
        yield 'whole pair kept' => ['a😀', 2, '😀'];
        yield 'shorter than limit' => ['abc', 10, 'abc'];
        yield 'zero' => ['abc', 0, ''];
        yield 'negative' => ['abc', -1, ''];
        yield 'exact length' => ['😀', 2, '😀'];
        yield 'low surrogate DC00 dropped' => ["\u{10000}ab", 3, 'ab'];
        yield 'low surrogate DFFF dropped' => ["\u{10FFFF}ab", 3, 'ab'];
        yield 'empty' => ['', 3, ''];
    }

    #[DataProvider('sliceEndProvider')]
    public function testUtf16SliceEnd(string $value, int $maxUnits, string $expected): void
    {
        $result = Js::utf16SliceEnd($value, $maxUnits);
        $this->assertSame($expected, $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function exceedsProvider(): iterable
    {
        yield 'ascii within' => ['abc', 3, false];
        yield 'ascii over' => ['abcd', 3, true];
        yield 'multibyte within in units' => ['ééé', 3, false];
        yield 'multibyte over in units' => ['éééé', 3, true];
        yield 'astral counts two units' => ['😀', 1, true];
        yield 'astral within' => ['😀', 2, false];
        yield 'empty' => ['', 0, false];
    }

    #[DataProvider('exceedsProvider')]
    public function testExceedsUtf16(string $value, int $max, bool $expected): void
    {
        $this->assertSame($expected, Js::exceedsUtf16($value, $max));
    }
}
