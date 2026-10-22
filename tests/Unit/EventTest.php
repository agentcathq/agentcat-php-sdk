<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testDefaults(): void
    {
        $event = new Event();

        $this->assertSame('', $event->id);
        $this->assertSame('', $event->sessionId);
        $this->assertNull($event->projectId);
        $this->assertSame('', $event->eventType);
        $this->assertSame('UTC', $event->timestamp->getTimezone()->getName());
        $this->assertLessThan(2, abs(time() - $event->timestamp->getTimestamp()));
        foreach (EventFields::toArray($event) as $name => $value) {
            if (!in_array($name, ['id', 'sessionId', 'eventType', 'timestamp'], true)) {
                $this->assertNull($value, $name);
            }
        }
    }

    public function testFieldOrder(): void
    {
        $this->assertSame(
            ['id', 'sessionId', 'projectId', 'eventType', 'timestamp', 'duration', 'inputTokens', 'outputTokens', 'ipAddress', 'sdkLanguage', 'agentcatVersion', 'serverName', 'serverVersion', 'clientName', 'clientVersion', 'identifyActorGivenId', 'identifyActorName', 'identifyActorData', 'resourceName', 'parameters', 'response', 'userIntent', 'isError', 'error', 'tags', 'properties', 'actorId'],
            array_keys(EventFields::toArray(new Event())),
        );
    }

    public function testCloneIsShallow(): void
    {
        $event = new Event(parameters: new \stdClass());
        $copy = clone $event;

        $this->assertSame($event->parameters, $copy->parameters);
    }
}
