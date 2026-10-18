<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\LaravelMcp;

use AgentCat\LaravelMcp\Internal\HandshakeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandshakeClientTest extends TestCase
{
    public function testThereIsNoClientBeforeAHandshake(): void
    {
        $this->assertNull((new HandshakeClient())->clientInfo());
    }

    /** @return iterable<string, array{mixed}> */
    public static function clients(): iterable
    {
        yield 'an array' => [['name' => 'desktop', 'version' => '0.9']];
        yield 'an object' => [(object) ['name' => 'desktop']];
        yield 'an empty array' => [[]];
    }

    #[DataProvider('clients')]
    public function testAnObjectLikeClientInfoIsKept(mixed $clientInfo): void
    {
        $handshake = new HandshakeClient();

        $handshake->record($clientInfo);

        $this->assertSame($clientInfo, $handshake->clientInfo());
    }

    /** @return iterable<string, array{mixed}> */
    public static function notClients(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['desktop'];
        yield 'a number' => [1];
        yield 'a closure' => [static fn(): null => null];
    }

    #[DataProvider('notClients')]
    public function testAnythingElseKeepsTheEarlierClient(mixed $other): void
    {
        $handshake = new HandshakeClient();
        $handshake->record(['name' => 'desktop', 'version' => '0.9']);

        $handshake->record($other);

        $this->assertSame(['name' => 'desktop', 'version' => '0.9'], $handshake->clientInfo());
    }

    public function testALaterHandshakeReplacesTheClient(): void
    {
        $handshake = new HandshakeClient();
        $handshake->record(['name' => 'desktop']);

        $handshake->record(['name' => 'cli']);

        $this->assertSame(['name' => 'cli'], $handshake->clientInfo());
    }
}
