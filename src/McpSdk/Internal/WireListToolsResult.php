<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use Mcp\Schema\Result\ListToolsResult;

/**
 * A `tools/list` result with AgentCat's injected tools. Its typed fields are the library's own listing, so a
 * customer's `ResponseEvent` listeners see what they see without AgentCat; its wire form is the exact JSON tree.
 * Both mcp/sdk encoders send the tree unchanged: the handshake era `json_encode()`s the response, and the modern era
 * encodes `(array) $result->jsonSerialize()`.
 *
 * @internal
 */
final class WireListToolsResult extends ListToolsResult
{
    public function __construct(ListToolsResult $original, private readonly \stdClass $tree)
    {
        parent::__construct($original->tools, $original->nextCursor);
    }

    /** @return array<string, mixed> the tree's members in order */
    #[\Override] // @phpstan-ignore method.childReturnType (the wire tree holds the tools as decoded JSON, not as Tool objects)
    public function jsonSerialize(): array
    {
        /** @var array<string, mixed> $members JSON member names; PHP keeps a numeric one as an int key */
        $members = get_object_vars($this->tree);

        return $members;
    }
}
