<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Events;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Tests\Support\Thrown;
use AgentCat\Tests\Support\WireEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventFieldsTest extends TestCase
{
    public function testAssignRoundTripsEveryField(): void
    {
        $full = new Event(
            id: 'i',
            sessionId: 's',
            projectId: 'p',
            eventType: 't',
            timestamp: new \DateTimeImmutable('2026-01-02T03:04:05.678Z'),
            duration: 1.5,
            inputTokens: 2,
            outputTokens: 3,
            ipAddress: '1.2.3.4',
            sdkLanguage: 'PHP',
            agentcatVersion: 'v',
            serverName: 'sn',
            serverVersion: 'sv',
            clientName: 'cn',
            clientVersion: 'cv',
            identifyActorGivenId: 'g',
            identifyActorName: 'n',
            identifyActorData: ['a' => 1],
            resourceName: 'r',
            parameters: ['p' => 1],
            response: 'resp',
            userIntent: 'u',
            isError: true,
            error: new \stdClass(),
            tags: ['k' => 'v'],
            properties: [1, 2],
            actorId: 'actor',
        );
        $target = new Event();

        EventFields::assign($target, EventFields::toArray($full));

        $this->assertEquals(EventFields::toArray($full), EventFields::toArray($target));
        $this->assertSame($full->timestamp, $target->timestamp);
        $this->assertSame('actor', $target->actorId);
        $this->assertTrue($target->isError);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function badAssignments(): iterable
    {
        yield 'userIntent' => ['userIntent', 5, 'Cannot assign int to property AgentCat\Event::$userIntent of type ?string'];
        yield 'tags value' => ['tags', ['a' => 1], 'Cannot assign array to property AgentCat\Event::$tags of type ?array'];
        yield 'tags scalar' => ['tags', 'x', 'Cannot assign string to property AgentCat\Event::$tags of type ?array'];
        yield 'timestamp' => ['timestamp', '2026', 'Cannot assign string to property AgentCat\Event::$timestamp of type DateTimeImmutable'];
        yield 'id null' => ['id', null, 'Cannot assign null to property AgentCat\Event::$id of type string'];
        yield 'duration' => ['duration', '5', 'Cannot assign string to property AgentCat\Event::$duration of type int|float|null'];
        yield 'inputTokens' => ['inputTokens', 1.5, 'Cannot assign float to property AgentCat\Event::$inputTokens of type ?int'];
        yield 'isError' => ['isError', 1, 'Cannot assign int to property AgentCat\Event::$isError of type ?bool'];
    }

    #[DataProvider('badAssignments')]
    public function testAssignRejectsWrongTypes(string $name, mixed $value, string $message): void
    {
        $e = Thrown::by(\TypeError::class, static function () use ($name, $value): void {
            EventFields::assign(new Event(), [$name => $value]);
        });
        $this->assertStringContainsString($message, $e->getMessage());
    }

    public function testUnknownNamesAreIgnored(): void
    {
        $event = WireEvents::g1();
        $before = EventFields::toArray($event);

        EventFields::assign($event, ['nope' => 1]);

        $this->assertSame($before, EventFields::toArray($event));
    }

    public function testNullIsAcceptedForNullableFields(): void
    {
        $event = new Event(duration: 3, userIntent: 'x', tags: ['a' => 'b']);

        EventFields::assign($event, ['userIntent' => null, 'duration' => null, 'tags' => null]);

        $this->assertNull($event->userIntent);
        $this->assertNull($event->duration);
        $this->assertNull($event->tags);
    }
}
