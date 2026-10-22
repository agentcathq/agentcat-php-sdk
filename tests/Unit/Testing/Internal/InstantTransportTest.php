<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Testing\Internal;

use AgentCat\Internal\Transport\Delivery;
use AgentCat\Testing\Internal\InstantTransport;
use PHPUnit\Framework\TestCase;

final class InstantTransportTest extends TestCase
{
    public function testStartRunsTheBeforeAttemptHookOnceAndCompletes(): void
    {
        $transport = new InstantTransport();
        $count = 0;

        $transport->start(new Delivery('http://x.test', '{}', 'evt_1', 's', static function () use (&$count): void {
            ++$count;
        }));
        $transport->pump();
        $this->assertTrue($transport->waitForActivity(INF));

        $this->assertSame(1, $count);
        $this->assertSame(0, $transport->inFlight());
    }

    public function testStartWithoutAHookDoesNothing(): void
    {
        $transport = new InstantTransport();

        $transport->start(new Delivery('http://x.test', '{}', 'evt_1', 's'));

        $this->assertSame(0, $transport->inFlight());
    }

    public function testDrainAndSendSucceedAtOnce(): void
    {
        $transport = new InstantTransport();
        $count = 0;

        $this->assertTrue($transport->drain(0.0));
        $this->assertTrue($transport->send(new Delivery('http://x.test', '{}', 'evt_1', 's', static function () use (&$count): void {
            ++$count;
        })));
        $this->assertSame(1, $count);
    }

    public function testTheClockIsMonotonicSeconds(): void
    {
        $transport = new InstantTransport();
        $first = $transport->now();
        usleep(1000);

        $this->assertGreaterThan($first, $transport->now());
        $this->assertLessThan(1e7, $first, 'seconds, not nanoseconds');
    }
}
