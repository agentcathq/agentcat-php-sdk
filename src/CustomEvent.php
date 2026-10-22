<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * The data of a custom event, for `publishCustomEvent()`. Use named arguments: the argument names are public API.
 * Every field is optional.
 */
final readonly class CustomEvent
{
    /**
     * @param string|null $sessionId The session to attribute the event to, for example the `session_id` of a task. It
     *                               wins over a session ID passed as the first argument of `publishCustomEvent()`.
     * @param string|null $resourceName What the event is about, for example `checkout`.
     * @param mixed $parameters Any JSON-encodable input data.
     * @param mixed $response Any JSON-encodable output data.
     * @param string|null $message Why it happened; sent as the event's user intent.
     * @param int|float|null $duration Duration in milliseconds.
     * @param bool|null $isError Whether the event is an error.
     * @param mixed $error JSON-encodable error details, sent as they are.
     * @param array<array-key, mixed>|null $tags Tags: string keys and string values. Invalid entries are left out and
     *                                           logged, like the tags of the `eventTags` option.
     * @param mixed $properties JSON-encodable properties; sent only when they have at least one entry.
     */
    public function __construct(
        public ?string $sessionId = null,
        public ?string $resourceName = null,
        public mixed $parameters = null,
        public mixed $response = null,
        public ?string $message = null,
        public int|float|null $duration = null,
        public ?bool $isError = null,
        public mixed $error = null,
        public ?array $tags = null,
        public mixed $properties = null,
    ) {}
}
