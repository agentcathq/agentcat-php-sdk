<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Json;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Json\JsonTree;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonTreeTest extends TestCase
{
    public function testATreeIsTheValueAsTheWireCarriesIt(): void
    {
        $tree = JsonTree::of(['a' => [], 'b' => (object) []]);

        $this->assertEquals(Json::decode('{"a":[],"b":{}}'), $tree);
        $this->assertSame('{"a":[],"b":{}}', json_encode($tree));
    }

    public function testATreeHasObjectsAsStdClassAndArraysAsLists(): void
    {
        $tree = JsonTree::of(['a' => ['b' => 1], 'list' => [1, 2], 'empty' => []]);

        $this->assertEquals((object) ['a' => (object) ['b' => 1], 'list' => [1, 2], 'empty' => []], $tree);
    }

    public function testInvalidUtf8BecomesTheReplacementCharacter(): void
    {
        $tree = JsonTree::of(['text' => "a\xB1b"]);

        $this->assertInstanceOf(\stdClass::class, $tree);
        $this->assertSame("a\u{FFFD}b", $tree->text);
    }

    public function testAValueJsonCannotCarryThrows(): void
    {
        $error = Thrown::by(\JsonException::class, static fn(): mixed => JsonTree::of(['n' => NAN]));

        $this->assertSame('Inf and NaN cannot be JSON encoded', $error->getMessage());
    }

    public function testAnEmptyArrayIsAnEmptyObject(): void
    {
        $this->assertEquals(new \stdClass(), JsonTree::object([]));
    }

    public function testIntegerKeysStayKeys(): void
    {
        $tree = JsonTree::object([0 => 'zero']);

        $this->assertSame('{"0":"zero"}', json_encode($tree));
        $this->assertSame('zero', $tree->{'0'});
    }

    public function testSequentialIntegerKeysStayAnObjectAndNestedValuesAreEncodedAsTheyAre(): void
    {
        $tree = JsonTree::object(['a', 'b', 'n' => ['a' => [], 'list' => [1]]]);

        $this->assertSame('{"0":"a","1":"b","n":{"a":[],"list":[1]}}', json_encode($tree));
    }

    public function testAnObjectJsonCannotCarryIsAnEmptyObject(): void
    {
        $this->assertEquals(new \stdClass(), JsonTree::object(['x' => NAN]));
        $this->assertEquals(new \stdClass(), JsonTree::object(['x' => new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): never
            {
                throw new \RuntimeException('no tree');
            }
        }]));
    }

    public function testAnEmptyListAsStructuredContentBecomesAnEmptyObject(): void
    {
        $tree = JsonTree::result(['content' => [['type' => 'text', 'text' => 'x']], 'structuredContent' => [], 'isError' => false]);

        $this->assertInstanceOf(\stdClass::class, $tree);
        $this->assertSame('{"content":[{"type":"text","text":"x"}],"structuredContent":{},"isError":false}', json_encode($tree));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function results(): iterable
    {
        yield 'a structured list' => [['structuredContent' => [1, 2]], '{"structuredContent":[1,2]}'];
        yield 'a structured object' => [['structuredContent' => ['echoed' => 'x']], '{"structuredContent":{"echoed":"x"}}'];
        yield 'an empty object' => [['structuredContent' => new \stdClass()], '{"structuredContent":{}}'];
        yield 'no structured content' => [['content' => []], '{"content":[]}'];
        yield 'a structured null' => [['structuredContent' => null], '{"structuredContent":null}'];
        yield 'an empty list' => [[], '[]'];
        yield 'a list' => [[['structuredContent' => []]], '[{"structuredContent":[]}]'];
        yield 'text' => ['text', '"text"'];
    }

    #[DataProvider('results')]
    public function testEveryOtherResultStaysAsItIs(mixed $result, string $json): void
    {
        $this->assertSame($json, json_encode(JsonTree::result($result)));
    }

    public function testAResultJsonCannotCarryThrows(): void
    {
        Thrown::by(\JsonException::class, static fn(): mixed => JsonTree::result(['structuredContent' => NAN]));
    }
}
