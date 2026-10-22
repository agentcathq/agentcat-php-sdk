<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\LaravelMcp;

use AgentCat\Internal\Json\Json;
use AgentCat\LaravelMcp\Internal\LaravelTrees;
use AgentCat\Tests\Support\JsonAssert;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LaravelTreesTest extends TestCase
{
    public function testTheToolsOfAListingAreItsJsonTree(): void
    {
        $response = JsonRpcResponse::result(1, ['tools' => [['name' => 'echo', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]]], 'nextCursor' => 'c']);

        $tools = LaravelTrees::tools($response);

        $this->assertEquals([(object) ['name' => 'echo', 'inputSchema' => (object) ['type' => 'object', 'properties' => new \stdClass()]]], $tools);
        $this->assertSame([], LaravelTrees::tools(JsonRpcResponse::result(1, ['tools' => []])));
    }

    /** @return iterable<string, array{mixed}> */
    public static function notListings(): iterable
    {
        yield 'not a response' => [['result' => ['tools' => []]]];
        yield 'an error' => [JsonRpcResponse::error(1, -32602, 'bad cursor')];
        yield 'an empty result' => [JsonRpcResponse::result(1, [])];
        yield 'a result object' => [new JsonRpcResponse(['id' => 1, 'result' => (object) ['tools' => []]])];
        yield 'no tools member' => [JsonRpcResponse::result(1, ['items' => []])];
        yield 'tools is an object' => [JsonRpcResponse::result(1, ['tools' => ['echo' => ['name' => 'echo']]])];
        yield 'tools is a string' => [JsonRpcResponse::result(1, ['tools' => 'echo'])];
    }

    #[DataProvider('notListings')]
    public function testAnythingElseHasNoTools(mixed $response): void
    {
        $this->assertNull(LaravelTrees::tools($response));
    }

    public function testAResultIsItsJsonTreeWithAnEmptyStructuredContentAsAnObject(): void
    {
        $response = JsonRpcResponse::result(1, ['content' => [['type' => 'text', 'text' => 'x']], 'isError' => false, 'structuredContent' => []]);

        $this->assertSame('{"content":[{"type":"text","text":"x"}],"isError":false,"structuredContent":{}}', json_encode(LaravelTrees::result($response)));
        $this->assertSame('{"structuredContent":[1]}', json_encode(LaravelTrees::result(JsonRpcResponse::result(1, ['structuredContent' => [1]]))));
        $this->assertNull(LaravelTrees::result(JsonRpcResponse::error(1, -32603, 'boom')));
    }

    public function testAResultJsonCannotCarryThrows(): void
    {
        $this->expectException(\JsonException::class);

        LaravelTrees::result(JsonRpcResponse::result(1, ['n' => NAN]));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function natives(): iterable
    {
        yield 'an object' => ['{"a":1,"b":{"c":[1,{"d":2}]}}', ['a' => 1, 'b' => ['c' => [1, ['d' => 2]]]]];
        yield 'a list' => ['[{"a":1},2]', [['a' => 1], 2]];
        yield 'a scalar' => ['"x"', 'x'];
        yield 'null' => ['null', null];
        yield 'keys out of order' => ['{"1":"b","0":"a"}', [1 => 'b', 0 => 'a']];
    }

    #[DataProvider('natives')]
    public function testATreeBecomesLaravelArrays(string $json, mixed $native): void
    {
        $this->assertSame($native, LaravelTrees::native(Json::decode($json)));
    }

    /** @return iterable<string, array{string}> */
    public static function objectsAnArrayWouldChange(): iterable
    {
        yield 'an empty object' => ['{}'];
        yield 'list keys' => ['{"0":"a","1":"b"}'];
        yield 'nested' => ['{"a":{},"b":[{}],"c":{"0":{"x":{}}}}'];
    }

    #[DataProvider('objectsAnArrayWouldChange')]
    public function testObjectsAnArrayWouldChangeStayObjects(string $json): void
    {
        $native = LaravelTrees::native(Json::decode($json));

        $this->assertSame($json, json_encode($native));
    }

    public function testNestedObjectsBecomeArraysUnderAnObjectThatStays(): void
    {
        $native = LaravelTrees::native(Json::decode('{"0":{"a":{"b":1}}}'));

        $this->assertInstanceOf(\stdClass::class, $native);
        $this->assertSame(['a' => ['b' => 1]], $native->{'0'});
    }

    public function testAResultIsAnArrayWithItsMetaAnArray(): void
    {
        $tree = Json::decode('{"content":[{"type":"text","text":"x"}],"structuredContent":{},"_meta":{"k":{"v":1}}}');
        $this->assertInstanceOf(\stdClass::class, $tree);

        $result = LaravelTrees::nativeResult($tree);

        $this->assertSame(['type' => 'text', 'text' => 'x'], JsonAssert::at($result, 'content', 0));
        $this->assertEquals(new \stdClass(), JsonAssert::at($result, 'structuredContent'));
        $meta = JsonAssert::at($result, '_meta');
        $this->assertIsArray($meta);
        $meta['io.modelcontextprotocol/serverInfo'] = ['name' => 's']; // what Server::send() does with result['_meta']
        $this->assertSame(['k' => ['v' => 1], 'io.modelcontextprotocol/serverInfo' => ['name' => 's']], $meta);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function results(): iterable
    {
        yield 'an empty meta' => ['{"content":[],"_meta":{}}', ['content' => [], '_meta' => []]];
        yield 'a list-keyed meta' => ['{"_meta":{"0":"a"}}', ['_meta' => [0 => 'a']]];
        yield 'a list meta' => ['{"_meta":[1]}', ['_meta' => [1]]];
        yield 'no meta' => ['{"isError":true}', ['isError' => true]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('results')]
    public function testAResultsMetaIsAlwaysAnArray(string $json, array $expected): void
    {
        $tree = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $tree);

        $this->assertSame($expected, LaravelTrees::nativeResult($tree));
    }
}
