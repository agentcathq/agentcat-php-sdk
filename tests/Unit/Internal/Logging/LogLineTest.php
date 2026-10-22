<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\LogLine;
use AgentCat\Internal\SdkVersion;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->assertMatchesRegularExpression('/\A\[sdk=\S+ php=\d+\.\d+\.\d+\S* mcp=(\d\S*|dev-\S+|unknown)(?: laravel-mcp=(\d\S*|dev-\S+))?\]\z/', $prefix);
        $this->assertSame(sprintf('[%s]', LogLine::versions(SdkVersion::get(), PHP_VERSION, SdkVersion::installed('mcp/sdk'), SdkVersion::installed('laravel/mcp'))), $prefix);
        $this->assertSame($prefix, LogLine::prefix());
    }

    /** @return iterable<string, array{?string, ?string, string}> */
    public static function libraryVersions(): iterable
    {
        yield 'mcp/sdk only' => ['0.8.1', null, 'sdk=1.0.0 php=8.3.6 mcp=0.8.1'];
        yield 'laravel/mcp only' => [null, '1.0.2', 'sdk=1.0.0 php=8.3.6 laravel-mcp=1.0.2'];
        yield 'both' => ['0.8.1', '1.0.2', 'sdk=1.0.0 php=8.3.6 mcp=0.8.1 laravel-mcp=1.0.2'];
        yield 'neither' => [null, null, 'sdk=1.0.0 php=8.3.6 mcp=unknown'];
    }

    #[DataProvider('libraryVersions')]
    #[Group('ac-03-35')]
    public function testThePrefixNamesTheInstalledMcpLibraries(?string $mcpSdk, ?string $laravelMcp, string $expected): void
    {
        $this->assertSame($expected, LogLine::versions('1.0.0', '8.3.6', $mcpSdk, $laravelMcp));
    }
}
