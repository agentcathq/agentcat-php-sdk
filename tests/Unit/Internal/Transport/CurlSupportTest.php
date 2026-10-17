<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Transport;

use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Transport\CurlSupport;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurlSupportTest extends TestCase
{
    /** @return iterable<string, array{float, float}> */
    public static function waits(): iterable
    {
        yield 'NAN' => [NAN, 0.0];
        yield 'negative' => [-1.0, 0.0];
        yield 'negative infinity' => [-INF, 0.0];
        yield 'zero' => [0.0, 0.0];
        yield 'inside' => [0.25, 0.25];
        yield 'the bound' => [1.0, 1.0];
        yield 'above' => [1.5, 1.0];
        yield 'INF' => [INF, 1.0];
    }

    /** PHP 8.4 changed `max(0.0, NAN)`: the bound is checked for NAN before `min()`/`max()` see it. */
    #[DataProvider('waits')]
    public function testAWaitIsBoundedByZeroAndTheMaximum(float $seconds, float $expected): void
    {
        $this->assertSame($expected, CurlSupport::waitSeconds($seconds, 1.0));
    }

    /** @return iterable<string, array{float, int}> */
    public static function timeouts(): iterable
    {
        yield 'the default connect timeout' => [CurlTransport::CONNECT_TIMEOUT_SECONDS, 2000];
        yield 'the default total timeout' => [CurlTransport::TOTAL_TIMEOUT_SECONDS, 10_000];
        yield 'rounded up' => [1.2346, 1235];
        yield 'rounded down' => [1.2344, 1234];
        yield 'half a millisecond' => [0.0005, 1];
        yield 'the largest' => [CurlSupport::MAX_TIMEOUT_SECONDS, 2_147_483_647];
    }

    #[DataProvider('timeouts')]
    public function testATimeoutIsWholeMilliseconds(float $seconds, int $milliseconds): void
    {
        $this->assertSame($milliseconds, CurlSupport::timeoutMilliseconds($seconds));
    }

    /** @return iterable<string, array{float, string}> */
    public static function badTimeouts(): iterable
    {
        yield 'NAN' => [NAN, 'NAN'];
        yield 'INF' => [INF, 'INF'];
        yield 'negative infinity' => [-INF, '-INF'];
        yield 'negative' => [-1.0, '-1.0'];
        yield 'zero (curl: no timeout)' => [0.0, '0.0'];
        yield 'under half a millisecond (rounds to 0)' => [0.0004, '0.0004'];
        yield 'above a 32-bit long of milliseconds' => [2_147_483.648, '2147483.648'];
    }

    #[DataProvider('badTimeouts')]
    public function testATimeoutThatCurlCannotTakeIsRejected(float $seconds, string $shown): void
    {
        $error = Thrown::by(\InvalidArgumentException::class, static fn(): int => CurlSupport::timeoutMilliseconds($seconds));

        $this->assertSame('A curl timeout must round to 1 through 2147483647 milliseconds, got ' . $shown . ' seconds', $error->getMessage());
    }

    /** curl_setopt_array() stops at the first rejected option: the options that take the response come first. */
    public function testThePostOptionsStartWithTheOptionsThatTakeTheResponse(): void
    {
        $options = CurlSupport::postOptions([CURLOPT_RETURNTRANSFER => true], 'https://x.test/v1', '{}', ['A: 1'], 2000, 10_000);

        $this->assertSame(CURLOPT_RETURNTRANSFER, array_key_first($options));
        $this->assertSame([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => 'https://x.test/v1',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_HTTPHEADER => ['A: 1', 'Expect:'],
            CURLOPT_USERAGENT => CurlSupport::userAgent(),
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 10_000,
        ], $options);
    }

    public function testTheUserAgentNamesTheSdkAndPhp(): void
    {
        $this->assertSame('agentcat-php/' . SdkVersion::get() . ' php/' . PHP_VERSION, CurlSupport::userAgent());
        $this->assertSame(CurlSupport::userAgent(), CurlTransport::userAgent());
    }
}
