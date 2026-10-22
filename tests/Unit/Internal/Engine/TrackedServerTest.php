<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\InjectedList;
use AgentCat\Internal\Engine\InjectionConfig;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Options;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

final class TrackedServerTest extends TestCase
{
    public function testInjectionConfigCopiesTheListingOptions(): void
    {
        $options = new Options(
            enableReportMissing: false,
            enableTracing: false,
            enableToolCallContext: false,
            customContextDescription: 'why',
            enableAgentTracking: true,
        );

        $this->assertEquals(
            new InjectionConfig(false, false, false, 'why', true, false),
            (new TrackedServer('proj', $options, static fn(): array => [null, null], $this->publisher()))->injectionConfig(),
        );
    }

    public function testHookModeFollowsResolveSessionId(): void
    {
        $plain = new TrackedServer('', new Options(), static fn(): array => [null, null], $this->publisher());
        $hooked = new TrackedServer('', new Options(resolveSessionId: static fn(): ?string => null), static fn(): array => [null, null], $this->publisher());

        $this->assertFalse($plain->hookMode());
        $this->assertFalse($plain->injectionConfig()->hookMode);
        $this->assertTrue($hooked->hookMode());
        $this->assertTrue($hooked->injectionConfig()->hookMode);
    }

    public function testMirrorAllowedAndAdopt(): void
    {
        $server = new TrackedServer('p', new Options(), static fn(): array => [null, null], $this->publisher());
        $this->assertTrue($server->mirrorAllowed('a'));

        $server->adopt(new InjectedList([], ['a' => []], ['b']));

        $this->assertFalse($server->mirrorAllowed('a'));
        $this->assertTrue($server->mirrorAllowed('b'));
        $this->assertSame(['a' => []], $server->registry);
        $this->assertSame(['b'], $server->outputRegistry);

        $server->adopt(new InjectedList([], ['c' => ['x']], []));
        $this->assertSame(['c' => ['x']], $server->registry);
        $this->assertSame([], $server->outputRegistry);
    }

    public function testServerInfoIsReadOnEveryCall(): void
    {
        $calls = 0;
        $server = new TrackedServer('p', new Options(), static function () use (&$calls): array {
            ++$calls;

            return $calls === 1 ? ['one', '1'] : ['two', null];
        }, $this->publisher());

        $this->assertSame(['one', '1'], $server->serverInfo());
        $this->assertSame(['two', null], $server->serverInfo());
        $this->assertSame('p', $server->projectId);
    }

    public function testThePublisherIsTheOwningClients(): void
    {
        $publisher = $this->publisher();

        $this->assertSame($publisher, (new TrackedServer('p', new Options(), static fn(): array => [null, null], $publisher))->publisher());
    }

    private function publisher(): Publisher
    {
        return new Publisher(new EventQueue(transport: new RecordingTransport()));
    }
}
