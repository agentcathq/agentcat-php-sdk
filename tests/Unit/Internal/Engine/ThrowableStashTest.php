<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Engine\ThrowableStash;
use PHPUnit\Framework\TestCase;

final class ThrowableStashTest extends TestCase
{
    public function testTakeReturnsTheExceptionOfThatRequestOnce(): void
    {
        $stash = new ThrowableStash();
        $first = new \stdClass();
        $second = new \stdClass();
        $error = new \RuntimeException('one');
        $stash->put($first, $error);

        $this->assertNull($stash->take($second));
        $this->assertSame($error, $stash->take($first));
        $this->assertNull($stash->take($first), 'taken');
        $this->assertNull($stash->take(null));
    }

    public function testAnEntryGoesAwayWithItsRequest(): void
    {
        $stash = new ThrowableStash();
        $request = new \stdClass();
        $error = new \RuntimeException('gone');
        $stash->put($request, $error);
        $reference = \WeakReference::create($error);

        unset($request, $error);

        $this->assertNull($reference->get(), 'the stash does not keep the exception alive');
    }
}
