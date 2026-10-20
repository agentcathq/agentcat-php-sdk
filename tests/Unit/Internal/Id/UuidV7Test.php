<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Id;

use AgentCat\Internal\Id\UuidV7;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class UuidV7Test extends TestCase
{
    /** @return iterable<string, array{string, string}> TS outputs, computed with the vendored KSUID code */
    public static function vectors(): iterable
    {
        yield 'session KSUID' => ['ses_2yXlShd4ODDcHLYliGW9VgH3m1R', '01977375-f600-7179-87c6-b00767670c45'];
        yield 'event KSUID' => ['evt_2yXlTL6bElM5Whm0HjT0Iz8LL0M', '01977376-0988-78fb-aa6b-36e16f376637'];
        yield 'not a KSUID' => ['my-customer-task-id', '634902a7-d0c7-7220-91c6-0fa56fad8828'];
        yield 'empty' => ['', 'f4c8996f-b924-73b0-8442-98fc1c149afb'];
        yield 'overflows 160 bits' => ['ses_session45600000000000000000', '6c350afa-fa75-7081-b0c6-8ad3272daa6c'];
        yield 'one prefix stripped' => ['abc_def_ghi', 'ae0466b7-1fe3-7984-a344-541280a247bb'];
        yield '27 dashes parse as zero' => ['x_' . str_repeat('-', 27), '0145f680-b000-7d7a-8884-df06b0d17ac0'];
        yield 'largest KSUID' => ['ses_aWgEPTl1tmebfsQzFP4bxwgy80V', '052df680-ac18-70fb-a3ad-b18287c0f14b'];
        yield 'largest KSUID + 1' => ['ses_aWgEPTl1tmebfsQzFP4bxwgy80W', '69af7543-b7cc-7b64-a50a-27922b46ef70'];
        yield 'upper-case prefix kept' => ['SES_2yXlShd4ODDcHLYliGW9VgH3m1R', '8bc15b2a-2661-7bac-a5b6-02eefdf24182'];
        yield 'all zeros' => ['ses_000000000000000000000000000', '0145f680-b000-72dd-9660-4a400f542404'];
        yield 'non-ASCII overflow' => ['ses_' . str_repeat('é', 27), '88417185-4ad5-75bc-9149-acad1fa28640'];
        yield 'astral: 27 UTF-16 units' => ['evt_' . str_repeat('😀', 13) . 'a', '0145f680-b000-713a-b075-2ae9fa3e6e15'];
    }

    #[DataProvider('vectors')]
    #[Group('ac-06-7')]
    public function testEveryVectorMatchesTypeScript(string $input, string $expected): void
    {
        $this->assertSame($expected, UuidV7::fromPrefixedId($input));
    }

    #[DataProvider('vectors')]
    #[Group('ac-06-8')]
    public function testVersionAndVariantNibbles(string $input, string $expected): void
    {
        $pattern = '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';
        $this->assertMatchesRegularExpression($pattern, UuidV7::fromPrefixedId($input));
        $this->assertMatchesRegularExpression($pattern, $expected);
    }

    #[Group('ac-06-8')]
    public function testRandomInputsAreValidUuidV7(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', UuidV7::fromPrefixedId(bin2hex(random_bytes(12))));
        }
    }

    #[Group('ac-06-9')]
    public function testNoClockIsRead(): void
    {
        $this->assertSame(UuidV7::fromPrefixedId('my-customer-task-id'), UuidV7::fromPrefixedId('my-customer-task-id'));
        $this->assertNotSame(UuidV7::fromPrefixedId('task-a'), UuidV7::fromPrefixedId('task-b'));
    }

    /** @return iterable<array{string, int}> 27 UTF-16 unit strings and `KSUID.parse(...).date.getTime()` of the vendored TypeScript code (node) */
    public static function typeScriptTimestamps(): iterable
    {
        yield ["\u{21}\u{2d}\u{2d}\u{21}\u{e9}\u{10000}0\u{2f}\u{20}\u{21}\u{ffff}\u{5b}\u{21}\u{7e}A\u{21}\u{1}\u{e9}0\u{e9}\u{e9}\u{2d}\u{1f600}\u{ffff}\u{5b}", 1_400_000_001_000];
        yield ["\u{21}a\u{21}\u{103ff}a\u{10ffff}\u{10ffff}a\u{7e}\u{3a}AzZ\u{10000}\u{1}1\u{5b}A\u{3a}19a\u{2d}", 1_400_000_001_000];
        yield ["a\u{10ffff}\u{1}\u{10000}Z9\u{20}\u{10ffff}\u{10000}A\u{2f}\u{20}\u{7e}\u{7e}9A\u{10000}9\u{10000}\u{2f}\u{2f}", 1_400_000_001_000];
        yield ["\u{e9}\u{103ff}\u{20}\u{10ffff}A0zA\u{20}1\u{10000}\u{ffff}\u{21}A\u{1f600}\u{2d}\u{1f600}\u{1}\u{10000}\u{5b}\u{2d}", 1_400_000_002_000];
        yield ["1\u{21}1\u{20}\u{2d}Z\u{103ff}\u{103ff}A\u{1}9\u{20}\u{10000}\u{1}\u{10ffff}zA1z\u{2d}\u{ff}a\u{ffff}", 1_400_000_006_000];
        yield ["\u{a1}\u{21}\u{2f}\u{21}0\u{a1}z\u{7e}\u{21}\u{1f600}\u{10ffff}\u{1f600}\u{21}\u{a1}\u{1f600}\u{1f600}\u{5b}\u{e9}aa\u{7e}Z", 1_400_000_012_000];
        yield ["0\u{3a}\u{7e}\u{7e}\u{2f}\u{a1}\u{2d}\u{21}\u{20}az\u{ffff}\u{2d}\u{10ffff}\u{1f600}\u{e9}\u{21}\u{2f}\u{e9}\u{7e}\u{10ffff}\u{ff}1\u{2d}", 1_400_000_032_000];
        yield ["\u{103ff}Z\u{103ff}\u{ffff}a\u{5b}\u{2d}\u{10ffff}\u{21}\u{10000}\u{e9}\u{2f}\u{ff}0\u{1f600}\u{10000}\u{2f}0\u{2d}1a", 1_400_000_114_000];
        yield ["\u{1f600}\u{103ff}\u{e9}\u{ff}aZ\u{20}\u{103ff}\u{103ff}\u{3a}\u{ff}\u{2d}0\u{21}\u{3a}\u{10ffff}\u{20}\u{10ffff}A\u{ff}\u{ffff}", 1_400_000_116_000];
        yield ["\u{3a}\u{e9}\u{10ffff}\u{103ff}\u{3a}\u{a1}9\u{2d}\u{5b}\u{2d}\u{5b}0\u{7e}\u{7e}\u{10000}\u{21}\u{3a}\u{5b}\u{5b}\u{ffff}\u{2d}\u{7e}\u{103ff}", 1_400_000_164_000];
        yield ["\u{5b}a\u{1f600}\u{2d}\u{ffff}0A9\u{10ffff}\u{ffff}A\u{2f}\u{1f600}\u{20}\u{20}\u{ff}\u{5b}\u{3a}\u{10000}aA99", 1_400_000_357_000];
        yield ["1\u{2d}\u{2d}\u{21}\u{ffff}\u{103ff}\u{21}\u{3a}\u{10ffff}\u{2d}\u{e9}\u{3a}\u{103ff}\u{21}\u{ffff}1\u{e9}Z\u{5b}\u{e9}\u{2f}Z\u{a1}\u{21}", 1_400_000_471_000];
        yield ["\u{7e}\u{ff}A\u{1f600}\u{10ffff}\u{103ff}\u{10000}\u{ffff}\u{2d}\u{20}\u{2d}\u{5b}z\u{2d}Z\u{20}\u{5b}a\u{e9}1\u{e9}\u{2d}1", 1_400_000_544_000];
        yield ["\u{3a}\u{5b}\u{3a}\u{21}\u{2d}1\u{10ffff}\u{2d}\u{ffff}z\u{21}\u{21}\u{10ffff}\u{7e}\u{ffff}\u{103ff}z\u{e9}\u{21}\u{5b}\u{21}\u{3a}\u{a1}A", 1_400_001_719_000];
        yield ["\u{103ff}\u{a1}\u{3a}\u{ff}\u{2f}ZZ\u{7e}\u{3a}\u{7e}0\u{1f600}\u{7e}\u{21}z\u{2d}\u{ffff}0\u{10ffff}\u{1f600}z\u{10ffff}", 1_400_007_089_000];
        yield ["\u{103ff}\u{10000}\u{2f}\u{1f600}\u{a1}\u{10000}Z\u{ff}\u{21}A\u{3a}\u{7e}\u{1}A\u{20}\u{10ffff}\u{1}\u{1}a\u{a1}\u{2d}Z", 1_400_007_203_000];
        yield ["\u{a1}\u{10000}z\u{e9}\u{10ffff}0\u{2d}\u{103ff}\u{20}\u{5b}11\u{ffff}z1\u{7e}\u{7e}\u{7e}0A\u{1}9\u{103ff}", 1_400_007_885_000];
        yield ["Z0\u{e9}aZ\u{103ff}\u{1f600}\u{7e}\u{2d}9\u{3a}z\u{a1}\u{a1}\u{a1}\u{e9}\u{3a}\u{7e}\u{1}\u{10000}A1\u{1}\u{ff}", 1_400_017_289_000];
        yield ["01\u{2d}\u{2d}\u{3a}\u{3a}Aa\u{21}\u{ffff}\u{3a}Z\u{10000}\u{21}A\u{3a}zZ\u{2d}\u{ff}1\u{ffff}9az\u{5b}", 1_400_029_085_000];
        yield ["\u{a1}\u{2d}\u{10ffff}z0z\u{1}Aa\u{3a}a\u{2f}0\u{10ffff}\u{ffff}AAA\u{103ff}\u{20}\u{21}\u{2d}\u{ffff}\u{5b}", 1_400_056_533_000];
        yield ["A\u{7e}\u{5b}\u{20}\u{ff}\u{3a}\u{10ffff}Zz\u{20}1\u{2d}\u{ff}\u{1f600}\u{a1}A9\u{5b}\u{2d}\u{ff}\u{ffff}\u{7e}\u{2f}9\u{3a}", 1_400_338_192_000];
        yield ["\u{1f600}0A\u{3a}\u{10000}\u{10000}\u{20}\u{a1}z9\u{7e}\u{3a}9z\u{7e}\u{1}\u{21}\u{7e}\u{20}\u{a1}\u{1f600}zz", 1_400_440_019_000];
        yield ["1\u{10ffff}\u{ffff}A\u{e9}\u{2d}\u{3a}AZ1\u{a1}A\u{10ffff}9\u{20}z0\u{3a}\u{10ffff}Z\u{3a}\u{2f}\u{5b}\u{21}", 1_400_456_570_000];
        yield ["9\u{ffff}\u{2f}a\u{20}A\u{5b}0\u{5b}\u{21}1\u{2d}\u{3a}\u{10ffff}\u{e9}\u{1f600}\u{ffff}1\u{21}\u{2d}\u{10ffff}0z\u{1}", 1_400_525_450_000];
        yield ["01\u{2d}\u{2d}1ZzA0\u{20}\u{e9}\u{7e}\u{20}\u{2d}\u{ff}\u{ffff}\u{1}\u{e9}\u{ffff}\u{2d}\u{1}\u{7e}\u{3a}\u{3a}\u{1}\u{ff}\u{5b}", 1_401_803_266_000];
        yield ["\u{ff}9\u{2d}9\u{1}Z\u{7e}Z\u{2f}\u{5b}\u{ffff}109\u{2f}\u{10000}z\u{7e}\u{103ff}\u{1}1\u{21}\u{2d}\u{e9}\u{1}", 1_405_938_626_000];
        yield ["\u{103ff}\u{e9}\u{2f}\u{ffff}z9\u{10ffff}\u{3a}\u{1}aAA\u{5b}\u{7e}\u{20}\u{ff}\u{a1}90\u{1f600}\u{a1}a\u{3a}\u{a1}", 1_427_252_587_000];
        yield ["\u{7e}\u{1f600}\u{21}00\u{2d}\u{5b}z9\u{7e}A\u{3a}a\u{21}\u{2f}\u{3a}0\u{a1}\u{e9}\u{21}9\u{21}\u{103ff}\u{20}9", 1_429_269_325_000];
        yield ["\u{7e}A\u{5b}\u{21}\u{20}\u{ffff}9\u{3a}\u{a1}\u{2f}\u{ffff}1\u{1}\u{1f600}a\u{e9}0\u{21}Z\u{1}0\u{e9}\u{ff}\u{5b}\u{2d}\u{e9}", 1_523_593_186_000];
        yield ["\u{e9}\u{ffff}0\u{2d}\u{a1}A\u{7e}Z\u{5b}\u{a1}\u{3a}\u{103ff}A\u{2f}\u{7e}\u{a1}Z9\u{ffff}\u{ffff}ZZ\u{5b}\u{ffff}0\u{20}", 3_728_962_873_000];
        yield ["a\u{10000}a\u{2f}\u{2d}a\u{0}\u{ffff}\u{1f600}\u{ffff}\u{ffff}a\u{2f}9\u{21}9\u{2d}Z\u{1}\u{10ffff}z1\u{1}0", 1_400_457_299_000];
        yield ["\u{10000}\u{2f}\u{2d}1\u{0}9\u{ffff}\u{21}19ZA\u{2d}\u{28}0\u{1}\u{103ff}9\u{10000}\u{20}A\u{10000}\u{21}", 1_400_439_533_000];
        yield ["\u{1f600}\u{20}Z\u{2f}\u{2d}\u{1}\u{1f600}\u{1f600}A00\u{0}A\u{2d}90a\u{2f}9\u{1f600}z\u{2f}\u{ffff}", 1_400_439_983_000];
        yield ["\u{1f600}\u{21}0Az\u{1f600}\u{0}a0Z0\u{10ffff}A\u{2f}A\u{ffff}\u{1}a\u{2f}\u{10ffff}\u{ffff}\u{10000}", 1_400_007_096_000];
        yield ["0\u{1f600}\u{2f}1\u{1}\u{2f}A\u{ffff}A\u{2f}9Zz\u{2d}\u{28}z\u{2f}\u{0}\u{21}Az\u{2d}\u{2f}z\u{2d}\u{2f}", 3_091_304_214_000];
        yield ["1\u{ffff}\u{2d}\u{ffff}\u{20}\u{ffff}91\u{ffff}\u{103ff}\u{28}\u{1f600}\u{103ff}\u{10000}0\u{2d}9A\u{2f}9A\u{2f}\u{1}", 1_400_008_277_000];
    }

    #[DataProvider('typeScriptTimestamps')]
    #[Group('ac-06-7')]
    public function testOddCharactersParseLikeTypeScript(string $encoded, int $expected): void
    {
        $this->assertSame($expected, UuidV7::ksuidMillis($encoded));
    }

    public function testTheKsuidTimestampFollowsTheTypeScriptParser(): void
    {
        $this->assertSame(1_749_988_800_000, UuidV7::ksuidMillis('2yXlShd4ODDcHLYliGW9VgH3m1R'));
        $this->assertSame(5_694_967_295_000, UuidV7::ksuidMillis('aWgEPTl1tmebfsQzFP4bxwgy80V'));
        $this->assertNull(UuidV7::ksuidMillis('aWgEPTl1tmebfsQzFP4bxwgy80W'), 'overflow throws in TS');
        $this->assertNull(UuidV7::ksuidMillis('zzzzzzzzzzzzzzzzzzzzzzzzzzz'));
        $this->assertNull(UuidV7::ksuidMillis('2yXlShd4ODDcHLYliGW9VgH3m1'), '26 characters');
        $this->assertSame(1_400_000_000_000, UuidV7::ksuidMillis(str_repeat('-', 27)), 'no character validation');
    }
}
