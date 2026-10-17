<?php

declare(strict_types=1);

namespace AgentCat;

/**
 * Who made a tool call, as returned by the `identify` option.
 */
final readonly class Identity
{
    /**
     * @param string $userId Your stable ID for the user.
     * @param string|null $userName Display name of the user.
     * @param array<array-key, mixed> $userData Any extra JSON-encodable facts about the user.
     */
    public function __construct(
        public string $userId,
        public ?string $userName = null,
        public array $userData = [],
    ) {}
}
