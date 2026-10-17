<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Internal\Telemetry\TraceContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TraceContextTest extends TestCase
{
    private const string SESSION = 'ses_2yXlShd4ODDcHLYliGW9VgH3m1R';

    private const string EVENT = 'evt_2yXlTL6bElM5Whm0HjT0Iz8LL0M';

    #[Group('ac-06-1')]
    public function testTheTraceIdIsTheFirst16BytesOfTheSessionHash(): void
    {
        $this->assertSame('b17987c6b00767670c45519276a327c3', TraceContext::traceId(self::SESSION));
    }

    #[Group('ac-06-2')]
    public function testTheSpanIdIsTheFirst8BytesOfTheEventHash(): void
    {
        $this->assertSame('38fbea6b36e16f37', TraceContext::spanId(self::EVENT));
    }

    #[Group('ac-06-3')]
    public function testDatadogIdsAreTheLow64BitsAsUnsignedDecimals(): void
    {
        $this->assertSame('884202591345649603', TraceContext::datadogTraceId(self::SESSION));
        $this->assertSame('4106133231481483063', TraceContext::datadogSpanId(self::EVENT));
    }

    #[Group('ac-06-4')]
    public function testValuesAboveTheSignedRangeStayUnsignedIntegers(): void
    {
        $this->assertSame('18446744073709551615', TraceContext::unsignedDecimal('ffffffffffffffff'));
        $this->assertSame('9223372036854775808', TraceContext::unsignedDecimal('8000000000000000'));
        $this->assertSame('9223372036854775807', TraceContext::unsignedDecimal('7fffffffffffffff'));
        $this->assertSame('1', TraceContext::unsignedDecimal('0000000000000001'));
        $this->assertSame('0', TraceContext::unsignedDecimal('0000000000000000'));
    }

    #[Group('ac-06-5')]
    public function testAnEmptySessionGetsANewRandomTraceIdEachTime(): void
    {
        $first = TraceContext::traceId('');
        $second = TraceContext::traceId('');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $first);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $second);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/\A[0-9]{1,20}\z/', TraceContext::datadogTraceId(''));
    }

    #[Group('ac-06-6')]
    public function testAnEmptyEventIdGetsARandomSpanId(): void
    {
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', TraceContext::spanId(''));
        $this->assertNotSame(TraceContext::spanId(''), TraceContext::spanId(''));
        $this->assertMatchesRegularExpression('/\A[0-9]{1,20}\z/', TraceContext::datadogSpanId(''));
    }

    public function testNonAsciiIdsAreHashedAsUtf8Bytes(): void
    {
        $this->assertSame(substr(hash('sha256', 'séance'), 0, 32), TraceContext::traceId('séance'));
    }
}
