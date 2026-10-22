<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Id;

use AgentCat\Internal\Id\EventId;
use AgentCat\Internal\Id\Ksuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class KsuidTest extends TestCase
{
    private const int EPOCH_MS = 1_400_000_000_000;

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function vectors(): iterable
    {
        yield 'sequence payload' => [1_700_000_000_000, implode('', array_map(chr(...), range(0, 15))), '2YBXZHqCjn9u9XPqNaTCk9OXKKF'];
        yield 'all zero' => [self::EPOCH_MS, str_repeat("\x00", 16), '000000000000000000000000000'];
        yield 'all ff' => [self::EPOCH_MS, str_repeat("\xff", 16), '000007n42DGM5Tflk9n8mt7Fhc7'];
    }

    #[DataProvider('vectors')]
    #[Group('ac-03-21')]
    public function testFromPartsMatchesTypeScript(int $timestampMs, string $payload, string $expected): void
    {
        $this->assertSame($expected, Ksuid::fromParts($timestampMs, $payload));
        $decoded = Ksuid::decode($expected);
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded['payload']);
        $this->assertSame(intdiv($timestampMs - self::EPOCH_MS, 1000), $decoded['timestamp']);
    }

    #[Group('ac-03-21')]
    public function testEventIdPrefixVector(): void
    {
        $this->assertSame(
            'evt_2YBXZHqCjn9u9XPqNaTCk9OXKKF',
            'evt_' . Ksuid::fromParts(1_700_000_000_000, implode('', array_map(chr(...), range(0, 15)))),
        );
    }

    public function testMaximumValueDecodesAndOverflowIsRejected(): void
    {
        $this->assertSame(
            ['timestamp' => 4_294_967_295, 'payload' => str_repeat("\xff", 16)],
            Ksuid::decode('aWgEPTl1tmebfsQzFP4bxwgy80V'),
        );
        $this->assertNull(Ksuid::decode('aWgEPTl1tmebfsQzFP4bxwgy80W'));
        $this->assertSame(
            'aWgEPTl1tmebfsQzFP4bxwgy80V',
            Ksuid::fromParts(self::EPOCH_MS + 1000 * 4_294_967_295, str_repeat("\xff", 16)),
        );
    }

    public function testDecodeRejectsMalformedInput(): void
    {
        $this->assertNull(Ksuid::decode(str_repeat('-', 27)));
        $this->assertNull(Ksuid::decode('abc'));
        $this->assertNull(Ksuid::decode(str_repeat('0', 28)));
        $this->assertNull(Ksuid::decode(str_repeat('0', 26) . '-'));
    }

    public function testFromPartsRejectsBadPayloadLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ksuid::fromParts(self::EPOCH_MS, str_repeat("\x00", 15));
    }

    public function testFromPartsRejectsLongPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ksuid::fromParts(self::EPOCH_MS, str_repeat("\x00", 17));
    }

    public function testFromPartsRejectsTimestampBeforeEpoch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ksuid::fromParts(1_399_999_999_999, str_repeat("\x00", 16));
    }

    public function testFromPartsRejectsTimestampAfterRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ksuid::fromParts(self::EPOCH_MS + 1000 * (2 ** 32 - 1) + 1, str_repeat("\x00", 16));
    }

    public function testRandomIsBase62WithCurrentTimestamp(): void
    {
        $first = Ksuid::random();
        $this->assertMatchesRegularExpression('/\A[0-9A-Za-z]{27}\z/', $first);
        $decoded = Ksuid::decode($first);
        $this->assertNotNull($decoded);
        $expected = intdiv((int) (microtime(true) * 1000) - self::EPOCH_MS, 1000);
        $this->assertEqualsWithDelta($expected, $decoded['timestamp'], 2);
        $this->assertNotSame($first, Ksuid::random());
    }

    public function testRandomHonoursExplicitTimestamp(): void
    {
        $decoded = Ksuid::decode(Ksuid::random(1_700_000_000_000));
        $this->assertNotNull($decoded);
        $this->assertSame(300_000_000, $decoded['timestamp']);
    }

    public function testEventIdMint(): void
    {
        $id = EventId::mint();
        $this->assertMatchesRegularExpression('/\Aevt_[0-9A-Za-z]{27}\z/', $id);
        $this->assertNotSame($id, EventId::mint());
    }
}
