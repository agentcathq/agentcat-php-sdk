<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Transport;

use AgentCat\Internal\Transport\Delivery;
use AgentCat\Tests\Support\WireEvents;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    #[Group('ac-07-8')]
    public function testForEventBuildsTheTypeScriptSummary(): void
    {
        $event = WireEvents::g1();
        $before = static function (): void {};

        $delivery = Delivery::forEvent($event, 'u', 'b', $before);

        $this->assertSame('u', $delivery->url);
        $this->assertSame('b', $delivery->body);
        $this->assertSame('evt_2nH0cX1kY7d8Zq3W5r6T9u0V1aB', $delivery->eventId);
        $this->assertSame(
            'mcp:tools/call | session ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0 | proj_2mZ9yX8wV7uT6sR5qP4oN3mL2kJ | 42 ms | anonymous',
            $delivery->summary,
        );
        $this->assertSame($before, $delivery->beforeAttempt);
        $this->assertNull(Delivery::forEvent($event, 'u', 'b')->beforeAttempt);
    }

    public function testGivenActorIdReplacesAnonymous(): void
    {
        $event = WireEvents::g1();
        $event->identifyActorGivenId = 'user_123';

        $this->assertStringEndsWith(' ms | user_123', Delivery::forEvent($event, 'u', 'b')->summary);
    }

    public function testEmptyGivenActorIdIsAnonymous(): void
    {
        $event = WireEvents::g1();
        $event->identifyActorGivenId = '';

        $this->assertStringEndsWith(' ms | anonymous', Delivery::forEvent($event, 'u', 'b')->summary);
    }

    public function testZeroGivenActorIdIsTruthyInJs(): void
    {
        $event = WireEvents::g1();
        $event->identifyActorGivenId = '0';

        $this->assertStringEndsWith(' ms | 0', Delivery::forEvent($event, 'u', 'b')->summary);
    }

    public function testMissingDurationPrintsUndefined(): void
    {
        $event = WireEvents::g1();
        $event->duration = null;

        $this->assertStringContainsString(' | undefined ms | ', Delivery::forEvent($event, 'u', 'b')->summary);
    }

    public function testFloatDurationPrintsLikeJs(): void
    {
        $event = WireEvents::g1();
        $event->duration = 12.5;
        $this->assertStringContainsString(' | 12.5 ms | ', Delivery::forEvent($event, 'u', 'b')->summary);

        $event->duration = 7.0;
        $this->assertStringContainsString(' | 7 ms | ', Delivery::forEvent($event, 'u', 'b')->summary);
    }

    public function testEmptySessionKeepsTheTemplateSpaces(): void
    {
        $event = WireEvents::g1();
        $event->sessionId = '';

        $this->assertStringStartsWith('mcp:tools/call | session  | proj_', Delivery::forEvent($event, 'u', 'b')->summary);
    }

    public function testMissingProjectPrintsUndefined(): void
    {
        $event = WireEvents::g1();
        $event->projectId = null;

        $this->assertSame(
            'mcp:tools/call | session ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0 | undefined | 42 ms | anonymous',
            Delivery::forEvent($event, 'u', 'b')->summary,
        );
    }

    public function testEventIdIsTheEventsId(): void
    {
        $event = WireEvents::g2();

        $delivery = Delivery::forEvent($event, 'u', 'b');

        $this->assertSame($event->id, $delivery->eventId);
        $this->assertSame(
            'mcp:tools/call | session ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0 | proj_2mZ9yX8wV7uT6sR5qP4oN3mL2kJ | 3 ms | user_123',
            $delivery->summary,
        );
    }

    public function testConstructorKeepsEveryField(): void
    {
        $delivery = new Delivery('https://x.test/v1/public/events/', '{}', 'evt_1', 'summary');

        $this->assertSame('https://x.test/v1/public/events/', $delivery->url);
        $this->assertSame('{}', $delivery->body);
        $this->assertSame('evt_1', $delivery->eventId);
        $this->assertSame('summary', $delivery->summary);
        $this->assertNull($delivery->beforeAttempt);
    }
}
