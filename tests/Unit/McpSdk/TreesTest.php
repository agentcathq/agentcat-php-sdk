<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\McpSdk;

use AgentCat\McpSdk\Internal\Trees;
use AgentCat\Tests\Support\Thrown;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAListingIsTheWireTreeAndItsTools(): void
    {
        $tool = new Tool(name: 'echo', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: null, annotations: null);

        $result = new ListToolsResult(['echo' => $tool, 'again' => $tool], 'c');

        $listing = Trees::listing($result);

        $this->assertNotNull($listing);
        [$listed, $tree, $tools] = $listing;
        $this->assertSame($result, $listed);
        $this->assertSame(['tools', 'nextCursor'], array_keys(get_object_vars($tree)));
        $this->assertCount(2, $tools);
        $this->assertSame($tree->tools, $tools);
        $this->assertEquals((object) ['name' => 'echo', 'inputSchema' => (object) ['type' => 'object', 'properties' => new \stdClass(), 'required' => []]], $tools[0]);
        $this->assertSame([], Trees::listing(new ListToolsResult([]))[2] ?? null);
    }

    public function testOnlyAToolListingIsAListing(): void
    {
        $other = new class implements ResultInterface {
            /** @return array<string, mixed> */
            #[\Override]
            public function jsonSerialize(): array
            {
                return ['tools' => []];
            }
        };

        $this->assertNull(Trees::listing(new CallToolResult([new TextContent('x')])));
        $this->assertNull(Trees::listing($other), 'a tools member is not enough');
        $this->assertNull(Trees::listing(null));
    }

    public function testACallToolResultIsItsOwnTypedReading(): void
    {
        $result = new CallToolResult([new TextContent('x')]);

        $this->assertSame($result, Trees::callToolResult($result));
    }

    public function testATreeIsReadTheWayTheLibraryReadsAnAnswer(): void
    {
        $tree = (object) [
            'content' => [(object) ['type' => 'text', 'text' => 'x'], (object) ['type' => 'image', 'data' => 'AA==', 'mimeType' => 'image/png']],
            'isError' => true,
            'structuredContent' => (object) ['n' => (object) ['m' => [1]]],
            '_meta' => (object) ['k' => 'v'],
        ];

        $result = Trees::callToolResult($tree);

        $this->assertCount(2, $result->content);
        [$text, $image] = array_values($result->content) + [null, null];
        $this->assertInstanceOf(TextContent::class, $text);
        $this->assertSame('x', $text->text);
        $this->assertInstanceOf(ImageContent::class, $image);
        $this->assertSame('image/png', $image->mimeType);
        $this->assertTrue($result->isError);
        $this->assertSame(['n' => ['m' => [1]]], $result->structuredContent);
        $this->assertSame(['k' => 'v'], $result->meta);
    }

    public function testAnotherResultTypeIsReadFromItsWireTree(): void
    {
        $other = new class implements ResultInterface {
            /** @return array<string, mixed> */
            #[\Override]
            public function jsonSerialize(): array
            {
                return ['content' => [['type' => 'text', 'text' => 'custom']]];
            }
        };

        $result = Trees::callToolResult($other);

        $this->assertCount(1, $result->content);
        $text = array_values($result->content)[0];
        $this->assertInstanceOf(TextContent::class, $text);
        $this->assertSame('custom', $text->text);
        $this->assertFalse($result->isError);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notToolResults(): iterable
    {
        yield 'no content' => [(object) ['tools' => []]];
        yield 'an unknown content type' => [(object) ['content' => [(object) ['type' => 'video']]]];
        yield 'not an object' => ['text'];
        yield 'not JSON' => [['n' => NAN]];
    }

    #[DataProvider('notToolResults')]
    public function testATreeThatIsNotAToolResultReadsAsOneWithoutContent(mixed $tree): void
    {
        $result = Trees::callToolResult($tree);

        $this->assertSame([], $result->content);
        $this->assertFalse($result->isError);
        $this->assertNull($result->structuredContent);
        $this->assertNull($result->meta);
    }
}
