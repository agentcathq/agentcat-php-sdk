<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Transport;

use AgentCat\Internal\Transport\RetryPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[Group('ac-03-4')]
    #[Group('ac-07-12')]
    public function testDefaultsMatchTypeScriptBackoff(): void
    {
        $policy = new RetryPolicy();

        // TS: Math.pow(2, retries) * 1000 ms for retries 0, 1, 2 (maxRetries = 3).
        $this->assertSame([1.0, 2.0, 4.0], $policy->delaysSeconds);
        $this->assertSame(4, $policy->maxAttempts());
        $this->assertSame(RetryPolicy::MAX_RETRIES + 1, $policy->maxAttempts());
        $this->assertEqualsWithDelta(1.0, $policy->delayAfterAttempt(1), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(2.0, $policy->delayAfterAttempt(2), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(4.0, $policy->delayAfterAttempt(3), PHP_FLOAT_EPSILON);
        $this->assertNull($policy->delayAfterAttempt(4));
        $this->assertNull($policy->delayAfterAttempt(5));
    }

    public function testCustomDelays(): void
    {
        $policy = new RetryPolicy([0.01]);

        $this->assertSame(2, $policy->maxAttempts());
        $this->assertEqualsWithDelta(0.01, $policy->delayAfterAttempt(1), PHP_FLOAT_EPSILON);
        $this->assertNull($policy->delayAfterAttempt(2));
    }

    public function testNoRetries(): void
    {
        $policy = new RetryPolicy([]);

        $this->assertSame(1, $policy->maxAttempts());
        $this->assertNull($policy->delayAfterAttempt(1));
    }

    public function testAttemptsBelowOneHaveNoDelay(): void
    {
        $policy = new RetryPolicy();

        $this->assertNull($policy->delayAfterAttempt(0));
        $this->assertNull($policy->delayAfterAttempt(-1));
    }
}
