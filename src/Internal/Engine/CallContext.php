<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\ToolCall;

/**
 * The request data of one `tools/call`, as the event records it and the customer hooks see it.
 *
 * @internal
 */
final readonly class CallContext
{
    /**
     * @param \stdClass $request becomes `parameters.request`: `{"method":"tools/call","params":{…}}`, raw arguments included
     * @param \stdClass $extra becomes `parameters.extra`
     * @param ToolCall $toolCall what the customer hooks receive
     * @param string|null $protocolVersion the protocol version the call claims (tag `agentcat_protocol_version`)
     * @param bool $continuation true for a retry round that carries the client's input responses (MRTR)
     */
    public function __construct(
        public \stdClass $request,
        public \stdClass $extra,
        public ToolCall $toolCall,
        public ?string $protocolVersion = null,
        public bool $continuation = false,
        public ?string $clientName = null,
        public ?string $clientVersion = null,
    ) {}
}
