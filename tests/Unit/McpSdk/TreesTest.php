<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\McpSdk;

use AgentCat\McpSdk\Internal\Trees;
use AgentCat\Tests\Support\Thrown;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\TestCase;

final class TreesTest extends TestCase
{
    public function testOfTurnsLibraryValuesIntoJsonTrees(): void
    {
        $tree = Trees::of(['a' => ['b' => 1], 'list' => [1, 2], 'empty' => []]);

        $this->assertEquals((object) ['a' => (object) ['b' => 1], 'list' => [1, 2], 'empty' => []], $tree);
    }

    public function testOfReplacesInvalidUtf8WithTheReplacementCharacter(): void
    {
        $tree = Trees::of(['text' => "a\xB1b"]);

        $this->assertInstanceOf(\stdClass::class, $tree);
        $this->assertSame("a\u{FFFD}b", $tree->text);
    }

    public function testOfThrowsForAValueJsonCannotCarry(): void
    {
        Thrown::by(\JsonException::class, static fn(): mixed => Trees::of(['n' => NAN]));
    }

    public function testEmptyArgumentsAreAnEmptyObject(): void
    {
        $this->assertEquals(new \stdClass(), Trees::arguments([]));
    }

    public function testArgumentsKeepIntegerKeysAndNestedEmptyLists(): void
    {
        $tree = Trees::arguments(['0' => 'zero', 'n' => ['a' => []]]);

        $this->assertInstanceOf(\stdClass::class, $tree);
        $this->assertSame(['0', 'n'], array_map(strval(...), array_keys(get_object_vars($tree))));
        $this->assertSame('zero', $tree->{'0'});
        $this->assertEquals((object) ['a' => []], $tree->n);
    }

    public function testArgumentsWithOnlySequentialIntegerKeysStayAnObject(): void
    {
        $tree = Trees::arguments(['a', 'b']);

        $this->assertInstanceOf(\stdClass::class, $tree);
        $this->assertSame('{"0":"a","1":"b"}', json_encode($tree));
    }

    public function testArgumentsJsonCannotCarryBecomeAnEmptyObject(): void
    {
        $this->assertEquals(new \stdClass(), Trees::arguments(['msg' => 'hi', 'n' => NAN]));
    }

    public function testAnEmptyListAsStructuredContentBecomesAnEmptyObject(): void
    {
        $tree = Trees::result(new CallToolResult([new TextContent('x')], structuredContent: []));

        $this->assertInstanceOf(\stdClass::class, $tree);
        $this->assertEquals(new \stdClass(), $tree->structuredContent);
        $this->assertEquals([(object) ['type' => 'text', 'text' => 'x']], $tree->content);
        $this->assertFalse($tree->isError);
    }

    public function testOtherStructuredContentStaysAsIs(): void
    {
        $list = Trees::result(new CallToolResult([new TextContent('x')], structuredContent: [1, 2]));
        $object = Trees::result(new CallToolResult([new TextContent('x')], structuredContent: ['echoed' => 'x']));
        $none = Trees::result(new CallToolResult([new TextContent('x')]));

        $this->assertInstanceOf(\stdClass::class, $list);
        $this->assertSame([1, 2], $list->structuredContent);
        $this->assertInstanceOf(\stdClass::class, $object);
        $this->assertEquals((object) ['echoed' => 'x'], $object->structuredContent);
        $this->assertInstanceOf(\stdClass::class, $none);
        $this->assertObjectNotHasProperty('structuredContent', $none);
    }

    public function testAResultThatIsNotAnObjectStaysAsIs(): void
    {
        $this->assertSame([], Trees::result([]));
        $this->assertSame('text', Trees::result('text'));
    }

    public function testToolsAreAListOfTrees(): void
    {
        $tool = new Tool(name: 'echo', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: null, annotations: null);

        $tools = Trees::tools(['echo' => $tool, 'again' => $tool]);

        $this->assertCount(2, $tools);
        $this->assertEquals((object) ['name' => 'echo', 'inputSchema' => (object) ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]], $tools[0]);
        $this->assertSame([], Trees::tools([]));
    }
}
