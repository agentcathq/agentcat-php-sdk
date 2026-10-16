<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal;

use AgentCat\Internal\SdkVersion;
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

    public function testResolveFallsBackOnNull(): void
    {
        $this->assertSame('dev', SdkVersion::resolve(static fn(): ?string => null));
    }

    public function testResolveFallsBackOnEmptyString(): void
    {
        $this->assertSame('dev', SdkVersion::resolve(static fn(): string => ''));
    }

    public function testResolveFallsBackWhenLookupThrows(): void
    {
        $this->assertSame('dev', SdkVersion::resolve(static function (): never {
            throw new \OutOfBoundsException('not installed');
        }));
    }
}
