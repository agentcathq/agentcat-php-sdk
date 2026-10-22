<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use Mcp\Schema\Result\CallToolResult;

/**
 * A `tools/call` result that AgentCat answers or decorates. Its typed fields are the tool's own result, so a
 * customer's `ResponseEvent` listeners see what they see without AgentCat; its wire form is the exact JSON tree.
 * Both mcp/sdk encoders send the tree unchanged: the handshake era `json_encode()`s the response, and the modern era
 * encodes `(array) $result->jsonSerialize()`.
 *
 * @internal
 */
final class WireCallToolResult extends CallToolResult
{
    public function __construct(CallToolResult $original, private readonly \stdClass $tree)
    {
        parent::__construct($original->content, $original->isError, $original->structuredContent, $original->meta);
    }

    /** @return array<string, mixed> the tree's members in order */
    #[\Override] // @phpstan-ignore method.childReturnType (the wire tree has the members of a tool result, decoded as JSON)
    public function jsonSerialize(): array
    {
        /** @var array<string, mixed> $members JSON member names; PHP keeps a numeric one as an int key */
        $members = get_object_vars($this->tree);

        return $members;
    }
}
