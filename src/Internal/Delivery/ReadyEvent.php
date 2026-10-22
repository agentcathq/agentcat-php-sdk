<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

use AgentCat\Event;

/**
 * An event that went through the pipeline, its hooks included, and waits for a free delivery slot. `EventQueue::flush()`
 * makes one of every waiting event, so each hook runs in the request of its event (an Octane worker serves the next
 * request in the same process), and the payload alone waits for the transport.
 *
 * @internal
 */
final readonly class ReadyEvent
{
    /**
     * @param string|null $body        the encoded event; null when it goes to the exporters only (no project, or no body)
     * @param string|null $encodeError why it has no body (`ErrorText::of()` of the encoder's exception)
     */
    public function __construct(
        public Event $event,
        public ?string $body,
        public ?string $encodeError = null,
    ) {}
}
