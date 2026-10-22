<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Events;

use AgentCat\Event;
use AgentCat\Internal\Events\SessionInfo;
use AgentCat\Internal\SdkVersion;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SessionInfoTest extends TestCase
{
    #[Group('ac-07-19')]
    #[Group('ac-01-86')]
    public function testStampSetsSessionFields(): void
    {
        $event = new Event(ipAddress: '9.9.9.9', identifyActorGivenId: 'old', identifyActorName: 'Old');

        (new SessionInfo('srv', '1.0', 'cli', '2.0'))->stamp($event);

        $this->assertNull($event->ipAddress);
        $this->assertSame('PHP', $event->sdkLanguage);
        $this->assertSame(SdkVersion::get(), $event->agentcatVersion);
        $this->assertSame('srv', $event->serverName);
        $this->assertSame('1.0', $event->serverVersion);
        $this->assertSame('cli', $event->clientName);
        $this->assertSame('2.0', $event->clientVersion);
        $this->assertNull($event->identifyActorGivenId);
        $this->assertNull($event->identifyActorName);
        $this->assertEquals(new \stdClass(), $event->identifyActorData);
    }

    public function testDefaultsAreNull(): void
    {
        $event = new Event();

        (new SessionInfo())->stamp($event);

        $this->assertNull($event->serverName);
        $this->assertNull($event->clientVersion);
    }
}
