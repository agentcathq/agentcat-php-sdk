<?php

declare(strict_types=1);

namespace AgentCat\Internal\Handles;

/**
 * The session and agent handles decided for one tool call.
 *
 * `sessionId` is empty for `invalid`, `foreign` and the provisional `hook` source.
 *
 * @internal
 */
final readonly class HandleResolution
{
    public function __construct(
        public string $sessionId,
        public SessionIdSource $source,
        public ?string $agentId = null,
        public bool $hookMode = false,
    ) {}
}
