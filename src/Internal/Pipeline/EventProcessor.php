<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Id\EventId;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;

/**
 * Runs a queued event through the pipeline stages in TypeScript order (`processEvent`).
 *
 * @internal
 */
final class EventProcessor
{
    /** Returns the same `Event` instance after all stages, or null when the event was dropped. Never throws. */
    public function process(QueuedEvent $queued): ?Event
    {
        $event = $queued->event;
        try {
            if ($queued->pending !== []) {
                $pending = $queued->pending;
                $queued->pending = [];
                try {
                    foreach ($pending as $apply) {
                        $apply($event);
                    }
                } catch (\Throwable $e) {
                    Log::write('Failed to resolve pending hook results: ' . ErrorText::of($e));
                }
            }
            if ($queued->redactEvent instanceof \Closure) {
                $hook = $queued->redactEvent;
                $queued->redactEvent = null;
                try {
                    if (!EventRedactionHook::apply($event, $hook)) {
                        Log::write('Event dropped by redactEvent hook');

                        return null;
                    }
                } catch (\Throwable $e) {
                    Log::write('Failed to redact event (event-level hook): ' . ErrorText::of($e));

                    return null;
                }
            }
            if ($queued->redactSensitiveInformation instanceof \Closure) {
                $redactor = $queued->redactSensitiveInformation;
                $queued->redactSensitiveInformation = null;
                try {
                    $this->adopt($event, StringRedactor::redact($event, $redactor));
                } catch (\Throwable $e) {
                    Log::write('Failed to redact event: ' . ErrorText::of($e));

                    return null;
                }
            }
            try {
                $this->adopt($event, Sanitizer::sanitize($event));
            } catch (\Throwable $e) {
                Log::write('Failed to sanitize event: ' . ErrorText::of($e));

                return null;
            }
            try {
                $this->adopt($event, Truncator::truncate($event));
            } catch (\Throwable $e) {
                Log::write('Failed to truncate event: ' . ErrorText::of($e));

                return null;
            }
            if ($event->id === '') {
                $event->id = EventId::mint();
            }

            return $event;
        } catch (\Throwable $e) {
            Log::write('Failed to deliver AgentCat event after retries: ' . ErrorText::of($e));

            return null;
        }
    }

    /** TS `Object.assign(event, stageResult)`: keep the instance, take every field. */
    private function adopt(Event $event, Event $stageResult): void
    {
        EventFields::assign($event, EventFields::toArray($stageResult));
    }
}
