<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Pipeline\EventRedactionHook;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class EventRedactionHookTest extends TestCase
{
    #[Group('ac-05-R15')]
    public function testHookEditsAreAppliedInPlace(): void
    {
        $event = new Event(id: 'evt_1', parameters: ['text' => 'secret'], response: 'resp');
        $observer = $event;

        $kept = EventRedactionHook::apply($event, static function (Event $e): Event {
            $e->parameters = ['text' => '[SCRUBBED]'];

            return $e;
        });

        $this->assertTrue($kept);
        $this->assertSame($observer, $event);
        $this->assertSame(['text' => '[SCRUBBED]'], $observer->parameters);
        $this->assertSame('resp', $event->response);
    }

    #[Group('ac-05-R16')]
    public function testNullDropsTheEvent(): void
    {
        $event = new Event(id: 'evt_1', userIntent: 'x');
        $before = serialize($event);

        $this->assertFalse(EventRedactionHook::apply($event, static fn(Event $e): ?Event => null));
        $this->assertSame($before, serialize($event));
    }

    #[Group('ac-05-R17')]
    #[Group('ac-01-52')]
    public function testHookCanClearFields(): void
    {
        $event = new Event(response: 'r', userIntent: 'u');

        EventRedactionHook::apply($event, static function (Event $e): Event {
            $e->response = null;
            $e->userIntent = null;

            return $e;
        });

        $this->assertNull($event->response);
        $this->assertNull($event->userIntent);
    }

    #[Group('ac-05-R18')]
    #[Group('ac-01-51')]
    public function testIdentityFieldsAreRestored(): void
    {
        $timestamp = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $event = new Event(id: 'evt_1', sessionId: 'ses_1', projectId: 'proj_1', eventType: 'mcp:tools/call', timestamp: $timestamp);

        EventRedactionHook::apply($event, static function (Event $e): Event {
            $e->id = 'evt_forged';
            $e->sessionId = 'ses_forged';
            $e->projectId = 'proj_forged';
            $e->eventType = 'forged:event';
            $e->timestamp = new \DateTimeImmutable('2000-01-01');

            return $e;
        });

        $this->assertSame('evt_1', $event->id);
        $this->assertSame('ses_1', $event->sessionId);
        $this->assertSame('proj_1', $event->projectId);
        $this->assertSame('mcp:tools/call', $event->eventType);
        $this->assertSame($timestamp, $event->timestamp);
        // @phpstan-ignore method.alreadyNarrowedType (pins the verbatim constant)
        $this->assertSame(['id', 'sessionId', 'projectId', 'eventType', 'timestamp'], EventRedactionHook::RESTORED_FIELDS);
    }

    #[Group('ac-05-R18')]
    public function testNullProjectIdStaysNull(): void
    {
        $event = new Event();

        EventRedactionHook::apply($event, static function (Event $e): Event {
            $e->projectId = 'proj_forged';

            return $e;
        });

        $this->assertNull($event->projectId);
    }

    #[Group('ac-05-R19')]
    public function testHookReceivesACloneWithOnlyDataFields(): void
    {
        $event = new Event(id: 'evt_1');
        $received = null;

        EventRedactionHook::apply($event, static function (Event $e) use (&$received): Event {
            $received = $e;

            return $e;
        });

        $this->assertInstanceOf(Event::class, $received);
        $this->assertNotSame($event, $received);
        $this->assertCount(27, EventFields::toArray($received));
    }

    public function testCopyIsShallow(): void
    {
        $nested = new \stdClass();
        $nested->q = 'secret';
        $event = new Event(parameters: $nested);

        $kept = EventRedactionHook::apply($event, static function (Event $e): ?Event {
            self::assertInstanceOf(\stdClass::class, $e->parameters);
            $e->parameters->q = 'mutated';

            return null;
        });

        $this->assertFalse($kept);
        $this->assertSame('mutated', $nested->q);
    }

    public function testHookExceptionPropagates(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hook failure');

        EventRedactionHook::apply(new Event(), static fn(Event $e): Event => throw new \RuntimeException('Hook failure'));
    }

    public function testInvalidReturnTypeIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('redactEvent must return AgentCat\Event or null, got string');

        // @phpstan-ignore argument.type (deliberately violates the hook contract)
        EventRedactionHook::apply(new Event(), static fn(Event $e): string => 'xyz');
    }

    public function testBadlyTypedTagsThrowTypeError(): void
    {
        $this->expectException(\TypeError::class);

        EventRedactionHook::apply(new Event(), static function (Event $e): Event {
            // @phpstan-ignore assign.propertyType (deliberately badly typed)
            $e->tags = ['a' => 1];

            return $e;
        });
    }
}
