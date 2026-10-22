<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\Sleep;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SleepTest extends TestCase
{
    #[MaximumDuration(1000)]
    public function testTheSleepUsesUsleep(): void
    {
        $asked = new \ArrayObject();
        $sleep = Sleep::closure(static function (string $function) use ($asked): bool {
            $asked[] = $function;

            return true;
        });
        $this->assertInstanceOf(\Closure::class, $sleep);

        $this->assertSleeps($sleep, 0.05);
        $this->assertSame(['usleep'], $asked->getArrayCopy(), 'checked once, when the sleep is made');
    }

    /** A wait sleeps at most 1 s; this one is longer, so that the whole seconds and the nanoseconds both count. */
    #[Group('hardened-host')]
    #[MaximumDuration(3000)]
    public function testWithoutUsleepTheSleepUsesTimeNanosleep(): void
    {
        $asked = new \ArrayObject();
        $sleep = Sleep::closure(static function (string $function) use ($asked): bool {
            $asked[] = $function;

            return $function !== 'usleep';
        });
        $this->assertInstanceOf(\Closure::class, $sleep);

        $this->assertSleeps($sleep, 0.05);
        $this->assertSleeps($sleep, 1.05);
        $this->assertSame(['usleep', 'time_nanosleep'], $asked->getArrayCopy());
    }

    #[Group('hardened-host')]
    public function testWithoutEitherFunctionThereIsNoSleep(): void
    {
        $this->assertNull(Sleep::closure(static fn(string $function): bool => false));
    }

    /** @param \Closure(float): void $sleep */
    private function assertSleeps(\Closure $sleep, float $seconds): void
    {
        $started = hrtime(true);

        $sleep($seconds);

        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual($seconds - 0.005, $elapsed);
        $this->assertLessThan($seconds + 0.5, $elapsed);
    }
}
