<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function testTheClockReadsHrtimeInSeconds(): void
    {
        $asked = new \ArrayObject();
        $clock = Clock::monotonic(static function (string $function) use ($asked): bool {
            $asked[] = $function;

            return true;
        });

        $before = hrtime(true) / 1e9;
        $first = $clock();
        $second = $clock();
        $after = hrtime(true) / 1e9;

        $this->assertGreaterThanOrEqual($before, $first);
        $this->assertGreaterThanOrEqual($first, $second);
        $this->assertLessThanOrEqual($after, $second);
        $this->assertSame(['hrtime'], $asked->getArrayCopy(), 'checked once, when the clock is made');
    }

    public function testTheDefaultClockReadsHrtime(): void
    {
        $before = hrtime(true) / 1e9;
        $now = Clock::monotonic()();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual(hrtime(true) / 1e9, $now);
    }

    /** A host can disable hrtime(): the clock then reads the wall clock. */
    public function testWithoutHrtimeTheClockReadsMicrotime(): void
    {
        $clock = Clock::monotonic(static fn(string $function): bool => $function !== 'hrtime');

        $before = microtime(true);
        $now = $clock();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual(microtime(true), $now);
    }
}
