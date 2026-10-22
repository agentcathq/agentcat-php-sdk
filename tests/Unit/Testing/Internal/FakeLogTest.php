<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Testing\Internal;

use AgentCat\Client;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Testing\Internal\FakeLog;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class FakeLogTest extends TestCase
{
    public function testEachClientHasTheLogAttachedToIt(): void
    {
        $client = $this->client();
        $log = FakeLog::attach($client);

        $this->assertSame($log, FakeLog::of($client));
        $this->assertNull(FakeLog::of($this->client()), 'a client of no fake');
        $this->assertNotSame($log, FakeLog::attach($this->client()));
    }

    public function testItKeepsEachMessageAsAStringWithoutItsLevel(): void
    {
        $log = FakeLog::attach($this->client());
        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'from a Stringable';
            }
        };

        $log->log(LogLevel::INFO, 'first');
        $log->warning($stringable);

        $this->assertSame(['first', 'from a Stringable'], $log->messages);
    }

    private function client(): Client
    {
        return new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport()), static fn(): bool => true));
    }
}
