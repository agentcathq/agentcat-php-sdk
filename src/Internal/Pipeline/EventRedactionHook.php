<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;

/**
 * Runs the customer's event-level `redactEvent` hook.
 *
 * @internal
 */
final class EventRedactionHook
{
    public const array RESTORED_FIELDS = ['id', 'sessionId', 'projectId', 'eventType', 'timestamp'];

    /**
     * Mutates `$event` in place with the hook's result; returns false when the hook dropped the event.
     *
     * @param \Closure(Event): (Event|null) $hook
     *
     * @throws \UnexpectedValueException when the hook returns neither an Event nor null
     * @throws \TypeError                when the result holds a badly typed field
     */
    public static function apply(Event $event, \Closure $hook): bool
    {
        $result = $hook(clone $event);
        if ($result === null) {
            return false;
        }
        // @phpstan-ignore instanceof.alwaysTrue (a customer callback can return any type at runtime)
        if (!$result instanceof Event) {
            throw new \UnexpectedValueException(sprintf('redactEvent must return %s or null, got %s', Event::class, get_debug_type($result)));
        }
        $restored = [
            'id' => $event->id,
            'sessionId' => $event->sessionId,
            'projectId' => $event->projectId,
            'eventType' => $event->eventType,
            'timestamp' => $event->timestamp,
        ];
        EventFields::assign($event, EventFields::toArray($result));
        EventFields::assign($event, $restored);

        return true;
    }
}
