<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Options;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class TrackedServersTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete();
    }

    public function testAServerHasTheStateItWasAddedWith(): void
    {
        $server = new \stdClass();
        $state = $this->state($server);
        $this->assertNull(TrackedServers::get($server));

        TrackedServers::add($server, $state);

        $this->assertSame($state, TrackedServers::get($server));
        $this->assertNull(TrackedServers::get(new \stdClass()));
    }

    public function testAnEntryGoesAwayWithItsServer(): void
    {
        $server = new \stdClass();
        TrackedServers::add($server, $this->state($server)); // the state refers back to its server, like a Builder's
        $gone = \WeakReference::create($server);

        unset($server);
        gc_collect_cycles();

        $this->assertNull($gone->get());
    }

    public function testTheIncompleteFlagIsProcessWideUntilReset(): void
    {
        $server = new \stdClass();
        $state = $this->state($server);
        TrackedServers::add($server, $state);
        TrackedServers::resetIncomplete();
        $this->assertFalse(TrackedServers::incomplete());

        TrackedServers::markIncomplete();
        TrackedServers::markIncomplete();

        $this->assertTrue(TrackedServers::incomplete());
        TrackedServers::resetIncomplete();
        $this->assertFalse(TrackedServers::incomplete());
        $this->assertSame($state, TrackedServers::get($server), 'the reset keeps the tracked servers');
    }

    private function state(object $server): TrackedServer
    {
        return new TrackedServer('proj', new Options(), static fn(): array => [$server::class, null], new Publisher(new EventQueue(transport: new RecordingTransport())));
    }
}
