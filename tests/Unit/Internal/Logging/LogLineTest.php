<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\LogLine;
use AgentCat\Internal\SdkVersion;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class LogLineTest extends TestCase
{
    public function testAFixedTimeIsFormattedInUtcWithMilliseconds(): void
    {
        $line = LogLine::format('m', new \DateTimeImmutable('2026-10-16T15:04:56.821Z'));

        $this->assertSame('[2026-10-16T15:04:56.821Z] ' . LogLine::prefix() . ' m', $line);
    }

    public function testAnotherTimeZoneIsConvertedToUtc(): void
    {
        $line = LogLine::format('m', new \DateTimeImmutable('2026-10-16T17:04:56.821+02:00'));

        $this->assertSame('[2026-10-16T15:04:56.821Z] ' . LogLine::prefix() . ' m', $line);
    }

    public function testWithoutATimeTheLineCarriesTheCurrentTime(): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $line = LogLine::format('now');
        $after = new \DateTimeImmutable('+1 second');

        $this->assertMatchesRegularExpression('/\A\[(\d{4}-\d\d\-\d\dT\d\d:\d\d:\d\d\.\d{3})Z\] ' . preg_quote(LogLine::prefix(), '/') . ' now\z/', $line);
        $stamp = new \DateTimeImmutable(substr($line, 1, 24));
        $this->assertGreaterThanOrEqual($before, $stamp);
        $this->assertLessThanOrEqual($after, $stamp);
    }

    #[Group('ac-03-38')]
    public function testThePrefixNamesTheSdkPhpAndMcpVersionsOnce(): void
    {
        $prefix = LogLine::prefix();

        $this->assertMatchesRegularExpression('/\A\[sdk=\S+ php=\d+\.\d+\.\d+\S* mcp=(v?\d\S*|dev-\S+|unknown)\]\z/', $prefix);
        $this->assertSame(sprintf('[sdk=%s php=%s mcp=%s]', SdkVersion::get(), PHP_VERSION, SdkVersion::installed('mcp/sdk') ?? 'unknown'), $prefix);
        $this->assertSame($prefix, LogLine::prefix());
    }
}
