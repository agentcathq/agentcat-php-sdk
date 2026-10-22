<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * What a library result means for the event.
 *
 * @internal
 */
final readonly class Observed
{
    /**
     * @param mixed $tree the tool-result tree of a successful answer
     * @param string|null $protocolError the message of a JSON-RPC error answer
     * @param \Throwable|null $thrown the tool's own exception, when the library caught one
     */
    public function __construct(
        public mixed $tree = null,
        public ?string $protocolError = null,
        public ?\Throwable $thrown = null,
    ) {}
}
