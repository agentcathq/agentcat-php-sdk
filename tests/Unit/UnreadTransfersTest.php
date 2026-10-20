<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Tests\Support\Thrown;
use AgentCat\Tests\Support\UnreadTransfers;
use PHPUnit\Framework\TestCase;

/** The test support that reads libcurl's queue of finished transfers fails closed: no handle is no check. */
final class UnreadTransfersTest extends TestCase
{
    public function testATransportWithoutAMultiHandleThrows(): void
    {
        $error = Thrown::by(\LogicException::class, static fn(): int => UnreadTransfers::of(new CurlTransport()));

        $this->assertSame('CurlMulti::$multi is null, not a CurlMultiHandle: no delivery started, or the member changed', $error->getMessage());
    }
}
