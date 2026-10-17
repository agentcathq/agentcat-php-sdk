<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Event;
use AgentCat\Internal\Handles\HandleResolution;

/**
 * The state of one traced `tools/call`, from setup to publish.
 *
 * @internal
 */
final readonly class TracedCall
{
    /**
     * @param list<\Closure(Event): void> $pending the customer hooks, run later by the event queue (D04)
     * @param string|null $invalidIntent the type of a `context` intent that is not a string; the event is then not
     *                                   published (header conflict 2, B16)
     */
    public function __construct(
        public TrackedServer $server,
        public Event $event,
        public HandleResolution $resolution,
        public CallContext $context,
        public array $pending,
        public \DateTimeImmutable $start,
        public ?string $invalidIntent = null,
    ) {}
}
