<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * A result that carries an exact JSON tree. Both mcp/sdk encoders send it unchanged: the handshake era
 * `json_encode()`s the response, and the modern era encodes `(array) $result->jsonSerialize()`.
 *
 * @internal
 */
final readonly class WireResult implements ResultInterface
{
    public function __construct(public \stdClass $tree) {}

    /** @return array<string, mixed> the tree's members in order */
    #[\Override]
    public function jsonSerialize(): array
    {
        /** @var array<string, mixed> $members JSON member names; PHP keeps a numeric one as an int key */
        $members = get_object_vars($this->tree);

        return $members;
    }
}
