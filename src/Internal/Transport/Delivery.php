<?php

declare(strict_types=1);

namespace AgentCat\Internal\Transport;

use AgentCat\Event;
use AgentCat\Internal\Support\Js;

/**
 * One encoded event on its way to the API. The same body (same event `id`) is sent on every attempt.
 *
 * @internal
 */
final readonly class Delivery
{
    /**
     * @param string                 $summary       the success log line after `Successfully sent event <id> | `
     * @param (\Closure(): void)|null $beforeAttempt runs before every attempt (B03: exporters run again on retries)
     */
    public function __construct(
        public string $url,
        public string $body,
        public string $eventId,
        public string $summary,
        public ?\Closure $beforeAttempt = null,
    ) {}

    /** @param (\Closure(): void)|null $beforeAttempt */
    public static function forEvent(Event $event, string $url, string $body, ?\Closure $beforeAttempt = null): self
    {
        // TS: `${eventType} | session ${sessionId} | ${projectId} | ${duration} ms | ${identifyActorGivenId || "anonymous"}`
        $summary = sprintf(
            '%s | session %s | %s | %s ms | %s',
            $event->eventType,
            $event->sessionId,
            $event->projectId ?? 'undefined',
            $event->duration === null ? 'undefined' : Js::string($event->duration),
            ($event->identifyActorGivenId ?? '') !== '' ? $event->identifyActorGivenId : 'anonymous',
        );

        return new self($url, $body, $event->id, $summary, $beforeAttempt);
    }
}
