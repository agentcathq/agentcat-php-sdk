<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Pipeline\Sanitizer;
use AgentCat\Internal\Pipeline\Truncator;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Samples\PlainThing;
use AgentCat\Tests\Support\Samples\SerializableThing;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TruncatorTest extends TestCase
{
    private function x(int $length): string
    {
        return str_repeat('x', $length);
    }

    private function object(mixed ...$properties): \stdClass
    {
        return (object) $properties;
    }

    private function textAt(mixed $value, string|int ...$path): string
    {
        $found = JsonAssert::at($value, ...$path);
        $this->assertIsString($found);

        return $found;
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function constantProvider(): iterable
    {
        yield 'depth' => ['MAX_DEPTH', 10];
        yield 'breadth' => ['MAX_BREADTH', 100];
        yield 'string' => ['MAX_STRING_LENGTH', 32_768];
        yield 'event bytes' => ['MAX_EVENT_BYTES', 102_400];
        yield 'user intent' => ['MAX_USER_INTENT_LENGTH', 2_048];
        yield 'error message' => ['MAX_ERROR_MESSAGE_LENGTH', 2_048];
        yield 'resource name' => ['MAX_RESOURCE_NAME_LENGTH', 256];
        yield 'metadata' => ['MAX_METADATA_LENGTH', 256];
        yield 'frames' => ['MAX_STACK_FRAMES', 50];
        yield 'content text' => ['MAX_CONTENT_TEXT_LENGTH', 32_768];
        yield 'suffix' => ['TRUNCATION_SUFFIX', '...'];
    }

    #[DataProvider('constantProvider')]
    public function testConstants(string $name, mixed $expected): void
    {
        $this->assertSame(Truncator::{$name}, $expected);
    }

    #[Group('ac-05-T2')]
    #[Group('ac-07-31')]
    public function testFieldLimits(): void
    {
        $r = Truncator::truncate(new Event(
            serverName: $this->x(300),
            serverVersion: $this->x(300),
            clientName: $this->x(300),
            clientVersion: $this->x(400),
            resourceName: $this->x(300),
            userIntent: $this->x(3000),
        ));
        $this->assertSame(2051, strlen((string) $r->userIntent));
        $this->assertStringEndsWith('...', (string) $r->userIntent);
        foreach ([$r->resourceName, $r->serverName, $r->serverVersion, $r->clientName, $r->clientVersion] as $value) {
            $this->assertSame(259, strlen((string) $value));
            $this->assertSame($this->x(256) . '...', $value);
        }
    }

    #[Group('ac-05-T2')]
    public function testShortAndNullFieldsAreUnchanged(): void
    {
        $r = Truncator::truncate(new Event(serverName: $this->x(256), resourceName: 'tool', userIntent: $this->x(2048)));
        $this->assertSame($this->x(256), $r->serverName);
        $this->assertSame($this->x(2048), $r->userIntent);
        $this->assertSame('tool', $r->resourceName);
        $this->assertNull($r->clientName);
        $this->assertNull($r->clientVersion);
        $this->assertNull($r->serverVersion);
    }

    #[Group('ac-05-T2')]
    public function testErrorMessageLimit(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(message: $this->x(3000))));
        $this->assertSame($this->x(2048) . '...', JsonAssert::at($r->error, 'message'));
        $r = Truncator::truncate(new Event(error: $this->object(message: 'short')));
        $this->assertSame('short', JsonAssert::at($r->error, 'message'));
        $r = Truncator::truncate(new Event(error: $this->object(message: $this->x(2048))));
        $this->assertSame($this->x(2048), JsonAssert::at($r->error, 'message'));
    }

    /** @return list<\stdClass> */
    private function frames(int $count): array
    {
        $frames = [];
        for ($i = 0; $i < $count; ++$i) {
            $frames[] = $this->object(filename: 'file' . $i);
        }

        return $frames;
    }

    #[Group('ac-05-T3')]
    public function testFramesAreCutToFirstAndLast25(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(frames: $this->frames(80))));
        $frames = JsonAssert::at($r->error, 'frames');
        $this->assertIsArray($frames);
        $this->assertCount(50, $frames);
        $this->assertSame('file0', JsonAssert::at($frames, 0, 'filename'));
        $this->assertSame('file24', JsonAssert::at($frames, 24, 'filename'));
        $this->assertSame('file55', JsonAssert::at($frames, 25, 'filename'));
        $this->assertSame('file79', JsonAssert::at($frames, 49, 'filename'));
    }

    #[Group('ac-05-T3')]
    public function testFramesBoundaries(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(frames: $this->frames(50))));
        $frames = JsonAssert::at($r->error, 'frames');
        $this->assertIsArray($frames);
        $this->assertCount(50, $frames);
        $this->assertSame('file25', JsonAssert::at($frames, 25, 'filename'));

        $r = Truncator::truncate(new Event(error: $this->object(frames: $this->frames(51))));
        $frames = JsonAssert::at($r->error, 'frames');
        $this->assertIsArray($frames);
        $this->assertCount(50, $frames);
        $this->assertSame('file24', JsonAssert::at($frames, 24, 'filename'));
        $this->assertSame('file26', JsonAssert::at($frames, 25, 'filename'));
        $this->assertSame('file50', JsonAssert::at($frames, 49, 'filename'));
    }

    #[Group('ac-05-T3')]
    public function testErrorWithoutFramesGetsNoFramesKey(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(message: 'm')));
        $this->assertInstanceOf(\stdClass::class, $r->error);
        $this->assertFalse(property_exists($r->error, 'frames'));
        $this->assertNotFalse(property_exists($r->error, 'message'));
    }

    #[Group('ac-05-T3')]
    public function testChainedErrorFramesAreNotCut(): void
    {
        $chained = $this->object(frames: $this->frames(80));
        $r = Truncator::truncate(new Event(error: $this->object(chained_errors: [$chained])));
        $this->assertCount(80, (array) JsonAssert::at($r->error, 'chained_errors', 0, 'frames'));
    }

    #[Group('ac-05-T3')]
    public function testErrorGivenAsArrayOrScalar(): void
    {
        $r = Truncator::truncate(new Event(error: ['message' => $this->x(3000)]));
        $this->assertSame($this->x(2048) . '...', JsonAssert::at($r->error, 'message'));
        $r = Truncator::truncate(new Event(error: ['a', 'b']));
        $this->assertSame('{"0":"a","1":"b"}', json_encode($r->error));
        $r = Truncator::truncate(new Event(error: 'boom'));
        $this->assertSame('boom', $r->error);
    }

    #[Group('ac-05-T4')]
    public function testContentTextIsCut(): void
    {
        $response = Json::decode('{"content":[{"type":"text","text":"' . $this->x(40000) . '"},{"type":"text","text":"short"}]}');
        $r = Truncator::truncate(new Event(response: $response));
        $text = JsonAssert::at($r->response, 'content', 0, 'text');
        $this->assertIsString($text);
        $this->assertSame(32771, strlen($text));
        $this->assertStringEndsWith('...', $text);
        $this->assertSame('short', JsonAssert::at($r->response, 'content', 1, 'text'));
        // the input is not mutated
        $this->assertSame(40000, strlen($this->textAt($response, 'content', 0, 'text')));
    }

    #[Group('ac-05-T4')]
    public function testContentTextOfArrayBlocksAndOtherBlocks(): void
    {
        $response = ['content' => [['type' => 'text', 'text' => $this->x(40000)], ['type' => 'image', 'text' => $this->x(40000)], 'str', ['type' => 'text', 'text' => 5]]];
        $r = Truncator::truncate(new Event(response: $response));
        $this->assertSame(32771, strlen($this->textAt($r->response, 'content', 0, 'text')));
        $this->assertSame(32771, strlen($this->textAt($r->response, 'content', 1, 'text'))); // normalized, not text-cut
        $this->assertSame('str', JsonAssert::at($r->response, 'content', 2));
        $this->assertSame(5, JsonAssert::at($r->response, 'content', 3, 'text'));
    }

    #[Group('ac-05-T4')]
    public function testResponseWithoutContentList(): void
    {
        $r = Truncator::truncate(new Event(response: $this->object(content: 'plain', other: 1)));
        $this->assertSame('plain', JsonAssert::at($r->response, 'content'));
        $r = Truncator::truncate(new Event(response: 'scalar'));
        $this->assertSame('scalar', $r->response);
        $r = Truncator::truncate(new Event(response: new SerializableThing()));
        $this->assertSame('{"kind":"thing","size":2}', json_encode($r->response));
    }

    /** @return array{Event, int} */
    private function truncated(Event $event): array
    {
        $result = Truncator::truncate($event);

        return [$result, WireEncoder::byteSize($result)];
    }

    #[Group('ac-05-T9')]
    #[Group('ac-07-33')]
    #[MaximumDuration(2000)]
    public function testOneHugeString(): void
    {
        [$r, $size] = $this->truncated(new Event(parameters: $this->object(data: $this->x(120000))));
        $this->assertSame(32771, strlen($this->textAt($r->parameters, 'data')));
        $this->assertLessThanOrEqual(102400, $size);
    }

    #[Group('ac-05-T9')]
    #[Group('ac-07-33')]
    #[MaximumDuration(2000)]
    public function testFourStringsUseTheLastResort(): void
    {
        $input = new Event(parameters: $this->object(a: $this->x(60000), b: $this->x(60000), c: $this->x(60000), d: $this->x(60000)));
        [$r, $size] = $this->truncated($input);
        $a = strlen($this->textAt($r->parameters, 'a'));
        $b = strlen($this->textAt($r->parameters, 'b'));
        $this->assertSame(16389, $a);
        $this->assertGreaterThan(16389, $b);
        $this->assertLessThan(32771, $b);
        $this->assertSame(32771, strlen($this->textAt($r->parameters, 'c')));
        $this->assertSame(32771, strlen($this->textAt($r->parameters, 'd')));
        $this->assertLessThanOrEqual(102400, $size);
        $this->assertGreaterThan(102400 - 1000, $size); // cut only as far as needed (+200 margin)
    }

    #[Group('ac-05-T9')]
    #[Group('ac-07-33')]
    #[MaximumDuration(2000)]
    public function testDeepNestingIsReduced(): void
    {
        $node = null;
        for ($level = 8; $level >= 1; --$level) {
            $next = $node;
            $node = $this->object(data: $this->x(15000));
            if ($next instanceof \stdClass) {
                $node->next = $next;
            }
        }
        [$r, $size] = $this->truncated(new Event(parameters: $node));
        $this->assertLessThanOrEqual(102400, $size);
        $dataLevels = 0;
        $cursor = $r->parameters;
        while ($cursor instanceof \stdClass) {
            if (property_exists($cursor, 'data')) {
                ++$dataLevels;
            }
            $cursor = $cursor->next ?? null;
        }
        $this->assertSame(6, $dataLevels);
        $this->assertSame('[Object]', $cursor);
    }

    #[Group('ac-05-T9')]
    #[Group('ac-07-33')]
    #[MaximumDuration(2000)]
    public function testManyMediumStrings(): void
    {
        $parameters = new \stdClass();
        for ($i = 0; $i < 50; ++$i) {
            $parameters->{'key' . $i} = $this->x(3000);
        }
        [$r, $size] = $this->truncated(new Event(parameters: $parameters, response: $this->object(data: $this->x(30000))));
        $this->assertLessThanOrEqual(102400, $size);
        $this->assertInstanceOf(\stdClass::class, $r->parameters);
        $this->assertCount(50, get_object_vars($r->parameters));
        foreach (get_object_vars($r->parameters) as $value) {
            $this->assertIsString($value);
            $this->assertTrue(strlen($value) === 3000 || str_ends_with($value, '...'));
        }
    }

    #[Group('ac-05-T9')]
    #[Group('ac-07-33')]
    public function testSmallEventIsUnchanged(): void
    {
        $input = new Event(
            id: 'evt_1',
            identifyActorData: $this->object(plan: 'pro'),
            parameters: $this->object(q: 'hello', n: 1),
            response: Json::decode('{"content":[{"type":"text","text":"hi"}]}'),
            error: $this->object(message: 'm'),
        );
        [$r, $size] = $this->truncated($input);
        $this->assertLessThan(102400, $size);
        $this->assertEquals($input->parameters, $r->parameters);
        $this->assertEquals($input->response, $r->response);
        $this->assertEquals($input->error, $r->error);
        $this->assertEquals($input->identifyActorData, $r->identifyActorData);
        $this->assertNotSame($input, $r);
    }

    #[Group('ac-05-T10')]
    #[MaximumDuration(2000)]
    public function testTimestampInstanceSurvivesTheLastResort(): void
    {
        $input = new Event(parameters: $this->object(a: $this->x(60000), b: $this->x(60000), c: $this->x(60000), d: $this->x(60000)));
        $r = Truncator::truncate($input);
        $this->assertSame($input->timestamp, $r->timestamp);
    }

    #[Group('ac-05-T11')]
    #[MaximumDuration(2000)]
    public function testProtectedFieldsAreIdentical(): void
    {
        $input = new Event(
            id: 'evt_1',
            sessionId: 'ses_1',
            projectId: 'proj_1',
            eventType: 'mcp:tools/call',
            duration: 12.5,
            ipAddress: '10.0.0.1',
            sdkLanguage: 'PHP',
            agentcatVersion: '1.0.0',
            parameters: $this->object(a: $this->x(60000), b: $this->x(60000), c: $this->x(60000), d: $this->x(60000)),
            isError: true,
        );
        $r = Truncator::truncate($input);
        $before = EventFields::toArray($input);
        $after = EventFields::toArray($r);
        foreach (['id', 'sessionId', 'projectId', 'eventType', 'timestamp', 'duration', 'sdkLanguage', 'agentcatVersion', 'ipAddress', 'isError'] as $field) {
            $this->assertArrayHasKey($field, $before);
            $this->assertArrayHasKey($field, $after);
            $this->assertSame($before[$field], $after[$field], $field);
        }
    }

    #[Group('ac-05-T12')]
    #[MaximumDuration(2000)]
    public function testInputIsNotMutated(): void
    {
        $input = new Event(
            parameters: $this->object(a: $this->x(60000), b: $this->x(60000), c: $this->x(60000), d: $this->x(60000)),
            response: Json::decode('{"content":[{"type":"text","text":"' . $this->x(40000) . '"}]}'),
            userIntent: $this->x(3000),
            error: $this->object(message: $this->x(3000), frames: $this->frames(80)),
        );
        $before = serialize($input);
        Truncator::truncate($input);
        $this->assertSame($before, serialize($input));
    }

    #[Group('ac-05-T13')]
    #[MaximumDuration(2000)]
    public function testSanitizeThenTruncate(): void
    {
        $base64 = substr(str_repeat('QUJDREVGR0hJSktMTU5PUA', 600), 0, 12000);
        $event = new Event(
            parameters: $this->object(imageData: $base64, query: 'hello', nested: $this->object(deep: $this->object(value: $this->x(40000)))),
            response: Json::decode('{"content":[{"type":"text","text":"' . $this->x(40000) . '"},{"type":"image","data":"iVBOR"}]}'),
            userIntent: $this->x(3000),
        );
        $r = Truncator::truncate(Sanitizer::sanitize($event));
        $this->assertSame(Sanitizer::BINARY_DATA_MARKER, JsonAssert::at($r->parameters, 'imageData'));
        $this->assertSame(Sanitizer::IMAGE_MARKER, JsonAssert::at($r->response, 'content', 1, 'text'));
        $this->assertSame(2051, strlen((string) $r->userIntent));
        $this->assertLessThanOrEqual(32771, strlen($this->textAt($r->response, 'content', 0, 'text')));
        $this->assertSame('hello', JsonAssert::at($r->parameters, 'query'));
        $this->assertLessThanOrEqual(102400, WireEncoder::byteSize($r));
    }

    #[Group('ac-05-T14')]
    public function testPropertiesAreNotNormalizedUnderBudget(): void
    {
        $properties = new \stdClass();
        for ($i = 0; $i < 150; ++$i) {
            $properties->{'k' . $i} = $i;
        }
        $deep = 'leaf';
        for ($i = 0; $i < 15; ++$i) {
            $deep = $this->object(n: $deep);
        }
        $properties->deep = $deep;
        $r = Truncator::truncate(new Event(properties: $properties));
        $this->assertSame($properties, $r->properties);
    }

    #[Group('ac-05-T14')]
    #[MaximumDuration(2000)]
    public function testPropertiesBlobIsCutByTheLastResort(): void
    {
        [$r, $size] = $this->truncated(new Event(properties: $this->object(blob: $this->x(120000))));
        $this->assertLessThanOrEqual(102400, $size);
        $this->assertStringEndsWith('...', $this->textAt($r->properties, 'blob'));
    }

    #[Group('ac-05-T14')]
    #[MaximumDuration(2000)]
    public function testTagValueIsCutWhenOverBudget(): void
    {
        $properties = new \stdClass();
        foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
            $properties->{$key} = $this->x(100000);
        }
        [$r, $size] = $this->truncated(new Event(tags: ['t' => $this->x(5000)], properties: $properties));
        $this->assertLessThanOrEqual(102400, $size);
        $this->assertNotNull($r->tags);
        $this->assertArrayHasKey('t', $r->tags);
        $this->assertLessThan(5000, strlen($r->tags['t']));
        $this->assertStringEndsWith('...', $r->tags['t']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function badMessageProvider(): iterable
    {
        yield 'int' => [5, 'error.message must be a string, got int'];
        yield 'object' => [(object) ['x' => 1], 'error.message must be a string, got stdClass'];
        yield 'bool' => [true, 'error.message must be a string, got bool'];
    }

    #[Group('ac-05-T15')]
    #[DataProvider('badMessageProvider')]
    public function testNonStringMessageThrows(mixed $message, string $expected): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($expected);
        Truncator::truncate(new Event(error: $this->object(message: $message)));
    }

    #[Group('ac-05-T15')]
    public function testListAndNullMessagesPass(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(message: ['a', 'b'])));
        $this->assertSame(['a', 'b'], JsonAssert::at($r->error, 'message'));
        $r = Truncator::truncate(new Event(error: $this->object(message: null)));
        $this->assertNull(JsonAssert::at($r->error, 'message'));
    }

    #[Group('ac-05-T15')]
    public function testLongListMessageIsJoinedAndCut(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(message: range(0, 2048))));
        $message = JsonAssert::at($r->error, 'message');
        $this->assertSame(Js::string(range(0, 2047)) . '...', $message);
        $r = Truncator::truncate(new Event(error: $this->object(message: range(1, 2048))));
        $this->assertCount(101, (array) JsonAssert::at($r->error, 'message')); // not joined; the normalizer applies its breadth cap
    }

    #[Group('ac-05-T15')]
    public function testStringFramesAreSpreadIntoCharacters(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(frames: $this->x(60))));
        $frames = JsonAssert::at($r->error, 'frames');
        $this->assertSame(array_fill(0, 50, 'x'), $frames);
        $r = Truncator::truncate(new Event(error: $this->object(frames: 'abc')));
        $this->assertSame('abc', JsonAssert::at($r->error, 'frames'));
        $r = Truncator::truncate(new Event(error: $this->object(frames: $this->x(50))));
        $this->assertSame($this->x(50), JsonAssert::at($r->error, 'frames'));
        $r = Truncator::truncate(new Event(error: $this->object(frames: $this->x(25) . 'y' . $this->x(25) . 'z')));
        $this->assertSame(array_merge(array_fill(0, 25, 'x'), array_fill(0, 24, 'x'), ['z']), JsonAssert::at($r->error, 'frames'));
    }

    #[Group('ac-05-T15')]
    public function testBadFramesThrowAndFalsyFramesPass(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(frames: 0)));
        $this->assertSame(0, JsonAssert::at($r->error, 'frames'));
        $r = Truncator::truncate(new Event(error: $this->object(frames: null)));
        $this->assertNull(JsonAssert::at($r->error, 'frames'));
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('error.frames must be a list or a string, got bool');
        Truncator::truncate(new Event(error: $this->object(frames: true)));
    }

    #[Group('ac-05-T16')]
    public function testContentTextCutNeverSplitsAnEmoji(): void
    {
        $response = $this->object(content: [$this->object(type: 'text', text: str_repeat('😀', 40000))]);
        $r = Truncator::truncate(new Event(response: $response));
        $text = JsonAssert::at($r->response, 'content', 0, 'text');
        $this->assertIsString($text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertLessThanOrEqual(32771, Js::utf16Length($text));
        $this->assertNotSame('', WireEncoder::encode($r));
    }

    #[Group('ac-05-T16')]
    public function testUserIntentCutDropsHalfAnEmoji(): void
    {
        $r = Truncator::truncate(new Event(userIntent: 'a' . str_repeat('😀', 1100)));
        $this->assertSame(2050, Js::utf16Length((string) $r->userIntent));
        $this->assertTrue(mb_check_encoding((string) $r->userIntent, 'UTF-8'));
    }

    #[MaximumDuration(2000)]
    public function testClosureCannotBeClonedInTheLastResort(): void
    {
        $event = new Event(properties: ['blob' => $this->x(120000), 'cb' => static fn(): int => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Closure could not be cloned');
        Truncator::truncate($event);
    }

    public function testClosureUnderBudgetPasses(): void
    {
        $event = new Event(properties: ['cb' => static fn(): int => 1, 'v' => 1]);
        $r = Truncator::truncate($event);
        $this->assertSame($event->properties, $r->properties);
    }

    #[MaximumDuration(2000)]
    public function testLastResortStructuredClone(): void
    {
        $when = new \DateTimeImmutable('2025-01-15T12:00:00Z');
        $thing = new SerializableThing();
        $properties = [
            'blob' => $this->x(120000),
            'when' => $when,
            'thing' => $thing,
            'plain' => new PlainThing(),
            'list' => [$this->x(10), 5],
            'obj' => $this->object(inner: 'v'),
            'enum' => \AgentCat\Tests\Support\Samples\BackedStatus::Active,
        ];
        [$r, $size] = $this->truncated(new Event(properties: $properties));
        $this->assertLessThanOrEqual(102400, $size);
        $body = (string) json_encode(WireEncoder::encode($r));
        $this->assertStringContainsString('2025-01-15T12:00:00.000Z', $body);
        $this->assertStringContainsString('kind', $body);
        $this->assertStringNotContainsString('hidden', $body);
        $this->assertStringContainsString('active', $body);
    }

    public function testBackReferenceThrowsCircularStructure(): void
    {
        $owner = new \stdClass();
        $owner->child = new \stdClass();
        $owner->child->parent = $owner;
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Converting circular structure to JSON');
        Truncator::truncate(new Event(properties: ['owner' => $owner]));
    }

    public function testThrowingJsonSerializablePropagates(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot serialize');
        Truncator::truncate(new Event(properties: ['x' => new SerializableThing(throw: true)]));
    }

    public function testPlainObjectOnlyExposesPublicState(): void
    {
        $r = Truncator::truncate(new Event(properties: ['p' => new PlainThing()]));
        $body = WireEncoder::encode($r);
        $this->assertStringContainsString('"visible":"v"', $body);
        $this->assertStringNotContainsString('hidden', $body);
    }

    public function testParametersAndIdentifyDataAreNormalized(): void
    {
        $r = Truncator::truncate(new Event(
            identifyActorData: ['when' => new \DateTimeImmutable('2025-01-15T12:00:00Z')],
            parameters: ['n' => NAN, 'f' => static fn(): int => 1],
            error: $this->object(message: 'm', extra: INF),
        ));
        $this->assertSame('{"n":"[NaN]","f":"[Function: <anonymous>]"}', json_encode($r->parameters));
        $this->assertSame('{"when":"2025-01-15T12:00:00.000Z"}', json_encode($r->identifyActorData));
        $this->assertSame('[Infinity]', JsonAssert::at($r->error, 'extra'));
    }

    public function testMultibyteValuesWithinTheUnitLimitAreKept(): void
    {
        $r = Truncator::truncate(new Event(
            serverName: str_repeat('é', 256),
            response: $this->object(content: [$this->object(type: 'text', text: str_repeat('é', 32768))]),
            userIntent: str_repeat('é', 2048),
        ));
        $this->assertSame(str_repeat('é', 2048), $r->userIntent);
        $this->assertSame(str_repeat('é', 256), $r->serverName);
        $this->assertSame(str_repeat('é', 32768), self::textAt($r->response, 'content', 0, 'text'));

        $r = Truncator::truncate(new Event(userIntent: str_repeat('é', 2049)));
        $this->assertSame(str_repeat('é', 2048) . '...', $r->userIntent);
    }

    public function testContentTextCutKeepsTheStartOfTheText(): void
    {
        $text = str_repeat('a', 32768) . str_repeat('b', 10);
        $r = Truncator::truncate(new Event(response: $this->object(content: [$this->object(type: 'text', text: $text)])));
        $this->assertSame(str_repeat('a', 32768) . '...', self::textAt($r->response, 'content', 0, 'text'));
    }

    #[Group('ac-05-T15')]
    public function testMultibyteStringFramesSplitByCharacter(): void
    {
        $r = Truncator::truncate(new Event(error: $this->object(frames: str_repeat('é', 60))));
        $this->assertSame(array_fill(0, 50, 'é'), JsonAssert::at($r->error, 'frames'));
    }

    public function testJsonSerializableErrorIsExpanded(): void
    {
        $r = Truncator::truncate(new Event(error: new SerializableThing()));
        $this->assertSame('{"kind":"thing","size":2}', json_encode($r->error));

        $scalar = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): string
            {
                return 'text';
            }
        };
        $r = Truncator::truncate(new Event(error: $scalar));
        $this->assertEquals(new \stdClass(), $r->error);
        $this->assertInstanceOf(\stdClass::class, $r->error);
    }

    private function sizedEvent(int $extra): Event
    {
        return new Event(parameters: $this->object(a: $this->x(32768), b: $this->x(32768), c: $this->x(32768), d: $this->x($extra)));
    }

    #[MaximumDuration(2000)]
    public function testSizeBudgetBoundary(): void
    {
        $base = WireEncoder::byteSize($this->sizedEvent(0));
        $exact = 102400 - $base;
        $this->assertGreaterThan(0, $exact);
        $this->assertLessThan(32768, $exact);

        $r = Truncator::truncate($this->sizedEvent($exact));
        $this->assertSame(102400, WireEncoder::byteSize($r));
        $this->assertSame($exact, strlen(self::textAt($r->parameters, 'd')));

        $r = Truncator::truncate($this->sizedEvent($exact + 1));
        $this->assertLessThanOrEqual(102400, WireEncoder::byteSize($r));
        $this->assertStringEndsWith('...', self::textAt($r->parameters, 'a'));
    }

    /** @return \stdClass nested objects, each with a `data` string, `$levels` deep */
    private function chain(int $levels, int $dataLength): \stdClass
    {
        $node = null;
        for ($level = $levels; $level >= 1; --$level) {
            $next = $node;
            $node = $this->object(data: $this->x($dataLength));
            if ($next instanceof \stdClass) {
                $node->next = $next;
            }
        }
        $this->assertInstanceOf(\stdClass::class, $node);

        return $node;
    }

    /** @return array{int, mixed} number of levels with `data`, and what ends the chain */
    private function chainShape(mixed $root): array
    {
        $levels = 0;
        $cursor = $root;
        while ($cursor instanceof \stdClass) {
            if (property_exists($cursor, 'data')) {
                ++$levels;
            }
            $cursor = $cursor->next ?? null;
        }

        return [$levels, $cursor];
    }

    public function testTenLevelsUnderBudgetAreKept(): void
    {
        [$levels, $end] = $this->chainShape(Truncator::truncate(new Event(parameters: $this->chain(10, 10)))->parameters);
        $this->assertSame(10, $levels);
        $this->assertNull($end);
    }

    #[MaximumDuration(2000)]
    public function testNineLevelsFitWhenTenDoNot(): void
    {
        $r = Truncator::truncate(new Event(parameters: $this->chain(10, 10300)));
        [$levels, $end] = $this->chainShape($r->parameters);
        $this->assertLessThanOrEqual(102400, WireEncoder::byteSize($r));
        $this->assertSame(9, $levels);
        $this->assertSame('[Object]', $end);
    }

    /** @return iterable<string, array{string}> */
    public static function normalizedFieldProvider(): iterable
    {
        yield 'parameters' => ['parameters'];
        yield 'response' => ['response'];
        yield 'identifyActorData' => ['identifyActorData'];
        yield 'error' => ['error'];
    }

    #[DataProvider('normalizedFieldProvider')]
    #[MaximumDuration(2000)]
    public function testEveryFreeFormFieldIsDepthReduced(string $field): void
    {
        $fields = [$field => $this->chain(8, 15000)];
        $event = new Event();
        EventFields::assign($event, $fields);
        $r = Truncator::truncate($event);
        $this->assertLessThanOrEqual(102400, WireEncoder::byteSize($r));
        $after = EventFields::toArray($r);
        $this->assertArrayHasKey($field, $after);
        [$levels, $end] = $this->chainShape($after[$field]);
        $this->assertSame(6, $levels);
        $this->assertSame('[Object]', $end);
    }

    #[MaximumDuration(2000)]
    public function testLastResortStartsFromDepthOne(): void
    {
        $parameters = $this->object(a: $this->x(60000), b: $this->x(60000), c: $this->x(60000), d: $this->x(60000), k: $this->object(inner: 'v'));
        $r = Truncator::truncate(new Event(parameters: $parameters));
        $this->assertSame('[Object]', JsonAssert::at($r->parameters, 'k'));
        $this->assertLessThanOrEqual(102400, WireEncoder::byteSize($r));
    }

    #[MaximumDuration(2000)]
    public function testLargestStringsAreCutFirst(): void
    {
        $r = Truncator::truncate(new Event(properties: $this->object(small: $this->x(30000), big: $this->x(110000))));
        $this->assertSame(30000, strlen(self::textAt($r->properties, 'small')));
        $this->assertLessThan(110000, strlen(self::textAt($r->properties, 'big')));
        $this->assertLessThanOrEqual(102400, WireEncoder::byteSize($r));
    }

    #[MaximumDuration(2000)]
    public function testShortStringsAreNeverCut(): void
    {
        $properties = new \stdClass();
        for ($i = 0; $i < 1200; ++$i) {
            $properties->{'k' . $i} = $this->x(100);
        }
        $r = Truncator::truncate(new Event(properties: $properties));
        $this->assertGreaterThan(102400, WireEncoder::byteSize($r));
        $this->assertSame($this->x(100), JsonAssert::at($r->properties, 'k0'));
    }

    #[MaximumDuration(2000)]
    public function testLastResortKeepsEnumsAndDates(): void
    {
        $r = Truncator::truncate(new Event(properties: [
            'blob' => $this->x(120000),
            'backed' => \AgentCat\Tests\Support\Samples\BackedStatus::Active,
            'unit' => \AgentCat\Tests\Support\Samples\UnitStatus::Active,
            'when' => new \DateTimeImmutable('2025-01-15T12:00:00Z'),
        ]));
        $body = WireEncoder::encode($r);
        $this->assertStringContainsString('"backed":"active"', $body);
        $this->assertStringContainsString('"unit":"[Enum: AgentCat\\\\Tests\\\\Support\\\\Samples\\\\UnitStatus::Active]"', $body);
        $this->assertStringContainsString('"when":"2025-01-15T12:00:00.000Z"', $body);
    }

    #[Group('ac-05-T3')]
    public function testShortFrameListsComeBackWhole(): void
    {
        foreach ([0, 1, 10, 30, 49] as $count) {
            $r = Truncator::truncate(new Event(error: $this->object(frames: self::frames($count))));
            $frames = JsonAssert::at($r->error, 'frames');
            $this->assertIsArray($frames);
            $this->assertCount($count, $frames);
            if ($count > 0) {
                $this->assertSame('file' . ($count - 1), JsonAssert::at($frames, $count - 1, 'filename'));
            }
        }
    }

    #[Group('ac-05-T12')]
    #[MaximumDuration(2000)]
    public function testLastResortDoesNotChangeTheInputObjects(): void
    {
        $properties = $this->object(blob: $this->x(120000), nested: $this->object(inner: $this->x(500)));
        $input = new Event(properties: $properties);
        $r = Truncator::truncate($input);
        $this->assertNotSame($properties, $r->properties);
        $this->assertSame(120000, strlen(self::textAt($properties, 'blob')));
        $this->assertSame(500, strlen(self::textAt($properties, 'nested', 'inner')));
    }

    #[Group('ac-05-T16')]
    public function testContentTextCutThroughAnEmojiDropsHalfAndAddsOneMarker(): void
    {
        $text = str_repeat('x', 32767) . '😀' . 'tail';
        $r = Truncator::truncate(new Event(response: $this->object(content: [$this->object(type: 'text', text: $text)])));
        $this->assertSame(str_repeat('x', 32767) . '...', self::textAt($r->response, 'content', 0, 'text'));
    }

    #[Group('ac-05-T16')]
    public function testContentTextCutBeforeAnEmojiKeepsItWhole(): void
    {
        $text = str_repeat('x', 32766) . '😀' . 'tail';
        $r = Truncator::truncate(new Event(response: $this->object(content: [$this->object(type: 'text', text: $text)])));
        $this->assertSame(str_repeat('x', 32766) . '😀...', self::textAt($r->response, 'content', 0, 'text'));
    }
}
