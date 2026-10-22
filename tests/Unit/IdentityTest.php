<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Identity;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase
{
    public function testDefaults(): void
    {
        $identity = new Identity('u1');

        $this->assertSame('u1', $identity->userId);
        $this->assertNull($identity->userName);
        $this->assertSame([], $identity->userData);
    }

    public function testNamedArgumentsAreKept(): void
    {
        $identity = new Identity(userId: 'u1', userName: 'Ada', userData: ['plan' => 'pro']);

        $this->assertSame('Ada', $identity->userName);
        $this->assertSame(['plan' => 'pro'], $identity->userData);
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new \ReflectionClass(Identity::class))->isReadOnly());
    }
}
