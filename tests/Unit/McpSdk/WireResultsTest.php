<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\McpSdk;

use AgentCat\McpSdk\Internal\WireCallToolResult;
use AgentCat\McpSdk\Internal\WireListToolsResult;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class WireResultsTest extends TestCase
{
    public function testACallToolResultKeepsTheOriginalsTypedFields(): void
    {
        $content = [new TextContent('mine')];
        $original = new CallToolResult($content, true, ['k' => 'v'], ['m' => 1]);

        $wire = new WireCallToolResult($original, (object) ['content' => []]);

        $this->assertSame($content, $wire->content);
        $this->assertTrue($wire->isError);
        $this->assertSame(['k' => 'v'], $wire->structuredContent);
        $this->assertSame(['m' => 1], $wire->meta);
    }

    public function testAListToolsResultKeepsTheOriginalsTypedFields(): void
    {
        $tools = ['echo' => new Tool(name: 'echo', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: null, annotations: null)];

        $wire = new WireListToolsResult(new ListToolsResult($tools, 'MQ=='), (object) ['tools' => []]);

        $this->assertSame($tools, $wire->tools);
        $this->assertSame('MQ==', $wire->nextCursor);
    }

    public function testTheHandshakeEncoderSendsTheTreeByteForByte(): void
    {
        $listing = (object) [
            'tools' => [(object) ['name' => 'a/b', 'inputSchema' => (object) ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]]],
            'nextCursor' => 'MQ==',
            'text' => 'é',
        ];
        $call = (object) ['content' => [(object) ['type' => 'text', 'text' => 'é/x']], 'structuredContent' => new \stdClass()];

        $this->assertSame(
            '{"jsonrpc":"2.0","id":1,"result":' . json_encode($listing, JSON_THROW_ON_ERROR) . '}',
            json_encode(new Response(1, new WireListToolsResult(new ListToolsResult([]), $listing)), JSON_THROW_ON_ERROR),
        );
        $this->assertSame(
            '{"jsonrpc":"2.0","id":2,"result":' . json_encode($call, JSON_THROW_ON_ERROR) . '}',
            json_encode(new Response(2, new WireCallToolResult(new CallToolResult([new TextContent('other')]), $call)), JSON_THROW_ON_ERROR),
        );
    }

    public function testTheModernEncoderKeepsTheTopLevelKeysInOrder(): void
    {
        $nested = new \stdClass();
        $listing = new WireListToolsResult(new ListToolsResult([]), (object) ['tools' => [], 'nextCursor' => 'c', '_meta' => $nested]);
        $call = new WireCallToolResult(new CallToolResult([]), (object) ['content' => [], 'structuredContent' => $nested, 'isError' => false]);

        $listed = $listing->jsonSerialize();
        $called = $call->jsonSerialize();

        $this->assertSame(['tools', 'nextCursor', '_meta'], array_keys($listed));
        $this->assertArrayHasKey('_meta', $listed);
        $this->assertSame($nested, $listed['_meta']);
        $this->assertSame(['content', 'structuredContent', 'isError'], array_keys($called));
        $this->assertArrayHasKey('structuredContent', $called);
        $this->assertSame($nested, $called['structuredContent']);
    }
}
