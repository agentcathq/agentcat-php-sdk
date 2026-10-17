<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Events\SessionInfo;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Support\Js;

/**
 * Stamps the events of tracked servers, tool calls and custom events, and hands them to the event queue (TS
 * `publishEvent`). Nothing customer-written runs here.
 *
 * @internal
 */
final readonly class Publisher
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private EventQueue $queue,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param list<\Closure(Event): void> $pending
     */
    public function publishToolCall(TrackedServer $server, Event $event, ?string $clientName, ?string $clientVersion, array $pending): void
    {
        if (!$server->options->enableTracing) {
            return; // TS publishEvent; tool calls never get here with tracing off
        }
        $this->stamp($server, $event, $clientName, $clientVersion);
        $this->queue->add(new QueuedEvent($event, $server->options->redactEvent, $server->options->redactSensitiveInformation, $pending));
    }

    /**
     * A custom event of a tracked server (TS `publishEvent` from `publishCustomEvent`): the tracked project replaces
     * the one the event has (B09), and only the `redactEvent` hook applies (D08). Nothing when tracing is off.
     */
    public function publishCustom(TrackedServer $server, Event $event): void
    {
        if (!$server->options->enableTracing) {
            return; // B09: dropped silently
        }
        $this->stamp($server, $event, null, null);
        $this->queue->add(new QueuedEvent($event, $server->options->redactEvent));
    }

    public function pump(): void
    {
        $this->queue->pump();
    }

    public function queue(): EventQueue
    {
        return $this->queue;
    }

    /** Whole milliseconds like the difference of two `Date.getTime()` values. */
    public static function elapsedMs(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return self::epochMs($to) - self::epochMs($from);
    }

    /** `Date.getTime()`: whole milliseconds since the Unix epoch. */
    private static function epochMs(\DateTimeImmutable $time): int
    {
        return (int) $time->format('Uv');
    }

    /** Server and SDK fields, the tracked project, and the elapsed time when the event has no duration. */
    private function stamp(TrackedServer $server, Event $event, ?string $clientName, ?string $clientVersion): void
    {
        [$serverName, $serverVersion] = $server->serverInfo();
        (new SessionInfo($serverName, $serverVersion, $clientName, $clientVersion))->stamp($event);
        $event->projectId = $server->projectId !== '' ? $server->projectId : null; // telemetry-only mode has no project (C16)
        if (!Js::truthy($event->duration)) {
            $event->duration = self::elapsedMs($event->timestamp, ($this->clock)());
        }
    }
}
