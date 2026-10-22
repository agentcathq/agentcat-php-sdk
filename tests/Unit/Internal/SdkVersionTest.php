<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal;

use AgentCat\Internal\SdkVersion;
use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

final class SdkVersionTest extends TestCase
{
    public function testReturnsNonEmptyString(): void
    {
        $this->assertNotSame('', SdkVersion::get());
    }

    public function testReturnsSameValueTwice(): void
    {
        $this->assertSame(SdkVersion::get(), SdkVersion::get());
    }

    public function testResolveReturnsPrettyVersion(): void
    {
        $this->assertSame('1.2.3', SdkVersion::resolve(static fn(): string => '1.2.3'));
    }

    /** The `v` of a tag name (`v1.0.0`) is not part of the version: the TypeScript SDK and the backend name `1.0.0`. */
    public function testResolveDropsTheLeadingVOfATag(): void
    {
        $this->assertSame('1.0.0', SdkVersion::resolve(static fn(): string => 'v1.0.0'));
        $this->assertSame('dev-main', SdkVersion::resolve(static fn(): string => 'dev-main'));
        $this->assertSame('1.x-dev', SdkVersion::resolve(static fn(): string => '1.x-dev'));
        $this->assertSame('dev', SdkVersion::resolve(static fn(): string => 'v'), 'never an empty version');
    }

    public function testResolveFallsBackOnNull(): void
    {
        $this->assertSame('dev', SdkVersion::resolve(static fn(): ?string => null));
    }

    public function testResolveFallsBackOnEmptyString(): void
    {
        $this->assertSame('dev', SdkVersion::resolve(static fn(): string => ''));
    }

    public function testInstalledReturnsThePrettyVersionOfAnInstalledPackage(): void
    {
        $version = SdkVersion::installed('mcp/sdk');
        $this->assertIsString($version);
        $this->assertNotSame('', $version);
    }

    /** Composer's pretty version of a tag keeps the tag's `v` (`v0.8.1`); the log prefix and diagnostics name `0.8.1`. */
    public function testInstalledDropsTheLeadingVOfATag(): void
    {
        $pretty = InstalledVersions::getPrettyVersion('mcp/sdk');
        $this->assertIsString($pretty);

        $installed = SdkVersion::installed('mcp/sdk');
        $console = SdkVersion::installed('symfony/console');
        $this->assertIsString($installed);
        $this->assertIsString($console);

        $this->assertSame(str_starts_with($pretty, 'v') ? substr($pretty, 1) : $pretty, $installed);
        $this->assertStringStartsNotWith('v', $installed);
        $this->assertStringStartsNotWith('v', $console);
    }

    public function testInstalledReturnsNullForAPackageThatIsNotInstalled(): void
    {
        $this->assertNull(SdkVersion::installed('nobody/nothing'));
    }

    public function testResolveFallsBackWhenLookupThrows(): void
    {
        $this->assertSame('dev', SdkVersion::resolve(static function (): never {
            throw new \OutOfBoundsException('not installed');
        }));
    }
}
