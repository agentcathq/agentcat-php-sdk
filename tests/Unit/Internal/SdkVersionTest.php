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
}
