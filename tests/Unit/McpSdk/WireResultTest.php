<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\McpSdk;

use AgentCat\McpSdk\Internal\WireResult;
use Mcp\Schema\JsonRpc\Response;
use PHPUnit\Framework\TestCase;

final class WireResultTest extends TestCase
{
    public function testTheHandshakeEncoderSendsTheTreeByteForByte(): void
    {
        $tree = (object) [
            'tools' => [(object) ['name' => 'a/b', 'inputSchema' => (object) ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]]],
            'nextCursor' => 'MQ==',
            'text' => 'é',
        ];

        $this->assertSame(
            '{"jsonrpc":"2.0","id":1,"result":' . json_encode($tree, JSON_THROW_ON_ERROR) . '}',
            json_encode(new Response(1, new WireResult($tree)), JSON_THROW_ON_ERROR),
        );
    }

    public function testTheModernEncoderKeepsTheTopLevelKeysInOrder(): void
    {
        $nested = new \stdClass();
        $wire = new WireResult((object) ['tools' => [], 'nextCursor' => 'c', 'structuredContent' => $nested]);

        $result = $wire->jsonSerialize();

        $this->assertSame(['tools', 'nextCursor', 'structuredContent'], array_keys($result));
        $this->assertArrayHasKey('structuredContent', $result);
        $this->assertSame($nested, $result['structuredContent']);
    }
}
