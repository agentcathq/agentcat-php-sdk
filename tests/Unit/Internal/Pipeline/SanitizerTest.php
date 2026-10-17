<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Pipeline\Sanitizer;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Samples\PlainThing;
use AgentCat\Tests\Support\Samples\SerializableThing;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase
{
    private const string IMAGE = '[image content redacted - not supported by AgentCat]';
    private const string AUDIO = '[audio content redacted - not supported by AgentCat]';
    private const string BINARY_RESOURCE = '[binary resource content redacted - not supported by AgentCat]';
    private const string BINARY_DATA = '[binary data redacted - not supported by AgentCat]';

    private static function b64(int $length): string
    {
        return substr(str_repeat('QUJDREVGR0hJSktMTU5PUA', (int) ceil($length / 22)), 0, $length);
    }

    /** @return list<mixed> */
    private function content(Event $event): array
    {
        $content = JsonAssert::at($event->response, 'content');
        $this->assertIsArray($content);
        $this->assertTrue(array_is_list($content));

        return $content;
    }

    private function sanitizeContent(string $contentJson): Event
    {
        return Sanitizer::sanitize(new Event(response: Json::decode('{"content":' . $contentJson . '}')));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function constantProvider(): iterable
    {
        yield 'image' => ['IMAGE_MARKER', self::IMAGE];
        yield 'audio' => ['AUDIO_MARKER', self::AUDIO];
        yield 'binary resource' => ['BINARY_RESOURCE_MARKER', self::BINARY_RESOURCE];
        yield 'binary data' => ['BINARY_DATA_MARKER', self::BINARY_DATA];
        yield 'size gate' => ['SIZE_GATE', 10240];
        yield 'pattern' => ['BASE64_PATTERN', '/\A[A-Za-z0-9+\/\n\r]++=*+\z/'];
    }

    #[DataProvider('constantProvider')]
    public function testConstants(string $name, mixed $expected): void
    {
        $this->assertSame(Sanitizer::{$name}, $expected);
    }

    #[Group('ac-05-S1')]
    public function testImageAndAudioBecomeTextMarkers(): void
    {
        $out = $this->sanitizeContent('[{"type":"text","text":"Hello world"},{"type":"image","data":"iVBOR","mimeType":"image/png"},{"type":"audio","data":"UklGR","mimeType":"audio/wav"}]');

        JsonAssert::assertSameJson(
            '[{"type":"text","text":"Hello world"},{"type":"text","text":"' . self::IMAGE . '"},{"type":"text","text":"' . self::AUDIO . '"}]',
            (string) Json::encodeJs($this->content($out)),
        );
        foreach (array_slice($this->content($out), 1) as $block) {
            $this->assertInstanceOf(\stdClass::class, $block);
            $this->assertSame(['type', 'text'], array_keys(get_object_vars($block)));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function blobProvider(): iterable
    {
        yield 'blob' => ['"blob":"AAAA"'];
        yield 'empty blob' => ['"blob":""'];
        yield 'null blob' => ['"blob":null'];
    }

    #[Group('ac-05-S2')]
    #[DataProvider('blobProvider')]
    public function testBlobResourceIsRedacted(string $blob): void
    {
        $out = $this->sanitizeContent('[{"type":"resource","resource":{"uri":"file:///x",' . $blob . ',"mimeType":"application/pdf"}}]');

        JsonAssert::assertSameJson(
            '[{"type":"text","text":"' . self::BINARY_RESOURCE . '"}]',
            (string) Json::encodeJs($this->content($out)),
        );
    }

    #[Group('ac-05-S3')]
    public function testTextResourcesAndLinksPassThroughByIdentity(): void
    {
        $blocks = Json::decode('[{"type":"resource","resource":{"uri":"u","text":"t"}},{"type":"resource"},{"type":"resource","resource":"x"},{"type":"resource_link","uri":"u"},{"type":"text","text":"t"}]');
        $this->assertIsArray($blocks);
        $out = Sanitizer::sanitize(new Event(response: (object) ['content' => $blocks]));

        $this->assertCount(5, $this->content($out));
        foreach (array_values($blocks) as $i => $expected) {
            $this->assertSame($expected, $this->content($out)[$i] ?? null);
        }
    }

    #[Group('ac-05-S4')]
    public function testUnsupportedTypes(): void
    {
        $out = $this->sanitizeContent('[{"type":"video"},{"data":"x"},{"type":5},[1]]');
        $marker = static fn(string $name): \stdClass => (object) ['type' => 'text', 'text' => '[unsupported content type "' . $name . '" redacted - not supported by AgentCat]'];

        $this->assertEquals([$marker('video'), $marker('undefined'), $marker('5'), $marker('undefined')], $this->content($out));
    }

    #[Group('ac-05-S5')]
    public function testScalarContentEntriesPassThrough(): void
    {
        $out = $this->sanitizeContent('[null,5,"str"]');

        $this->assertSame([null, 5, 'str'], $this->content($out));
    }

    #[Group('ac-05-S6')]
    public function testSizeGateIsInclusive(): void
    {
        $at = str_repeat('A', 10239) . '=';
        $below = str_repeat('A', 10238) . '=';
        $out = Sanitizer::sanitize(new Event(parameters: ['imageData' => $at, 'other' => $below]));

        $this->assertSame(['imageData' => self::BINARY_DATA, 'other' => $below], $out->parameters);
    }

    #[Group('ac-05-S7')]
    public function testNestedBase64IsReplacedAndSiblingsStay(): void
    {
        $big = self::b64(12000);
        $parameters = Json::decode('{"level1":{"level2":{"level3":{"data":"BIG"}}},"list":["BIG",{"x":"BIG"}],"n":42,"t":true,"s":"short","a":[1,2]}');
        $this->assertInstanceOf(\stdClass::class, $parameters);
        $parameters->level1 = (object) ['level2' => (object) ['level3' => (object) ['data' => $big]]];
        $parameters->list = [$big, (object) ['x' => $big]];
        $response = (object) ['structuredContent' => (object) ['data' => $big, 'label' => 'x']];

        $out = Sanitizer::sanitize(new Event(parameters: $parameters, response: $response));

        JsonAssert::assertSameJson(
            '{"level1":{"level2":{"level3":{"data":"' . self::BINARY_DATA . '"}}},"list":["' . self::BINARY_DATA . '",{"x":"' . self::BINARY_DATA . '"}],"n":42,"t":true,"s":"short","a":[1,2]}',
            (string) Json::encodeJs($out->parameters),
        );
        JsonAssert::assertSameJson(
            '{"structuredContent":{"data":"' . self::BINARY_DATA . '","label":"x"}}',
            (string) Json::encodeJs($out->response),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function base64Provider(): iterable
    {
        yield 'prose' => [str_repeat('Hello, world! This is NOT base64. ', 400), false];
        yield 'base64url' => [str_repeat('ab-_', 3000), false];
        yield 'data uri' => ['data:image/png;base64,' . self::b64(12000), false];
        yield 'spaces' => [str_repeat('QUJD ', 3000), false];
        yield 'mime lf' => [implode("\n", str_split(self::b64(12000), 76)), true];
        yield 'mime crlf' => [implode("\r\n", str_split(self::b64(12000), 76)), true];
        yield 'letters only' => [str_repeat('x', 80000), true];
        yield 'padding then more' => [self::b64(12000) . '=A', false];
        yield 'trailing newline' => [self::b64(12000) . "\n", true];
        yield 'multibyte' => [self::b64(12000) . 'é', false];
    }

    #[Group('ac-05-S8')]
    #[DataProvider('base64Provider')]
    public function testBase64Detection(string $value, bool $redacted): void
    {
        $out = Sanitizer::sanitize(new Event(parameters: ['v' => $value]));

        $this->assertSame(['v' => $redacted ? self::BINARY_DATA : $value], $out->parameters);
    }

    #[Group('ac-05-S9')]
    public function testNullsAndMissingContentAreUnchanged(): void
    {
        $out = Sanitizer::sanitize(new Event());
        $this->assertNull($out->response);
        $this->assertNull($out->parameters);

        $out = Sanitizer::sanitize(new Event(response: ['result' => 'success']));
        $this->assertEquals((object) ['result' => 'success'], $out->response);
    }

    #[Group('ac-05-S9')]
    public function testOtherFieldsAreNotScanned(): void
    {
        $big = self::b64(12000);
        $event = new Event(
            identifyActorData: (object) ['blob' => $big],
            response: (object) ['_meta' => (object) ['blob' => $big], 'custom' => $big],
            userIntent: $big,
            error: (object) ['message' => $big],
            properties: (object) ['blob' => $big],
        );

        $out = Sanitizer::sanitize($event);

        $this->assertSame($big, $out->userIntent);
        $this->assertEquals($event->error, $out->error);
        $this->assertEquals($event->identifyActorData, $out->identifyActorData);
        $this->assertEquals($event->properties, $out->properties);
        $this->assertEquals($event->response, $out->response);
    }

    #[Group('ac-05-S10')]
    public function testInputIsNotMutated(): void
    {
        $event = new Event(
            parameters: (object) ['d' => self::b64(12000)],
            response: Json::decode('{"content":[{"type":"image","data":"x"}]}'),
        );
        $before = serialize($event);

        $out = Sanitizer::sanitize($event);

        $this->assertSame($before, serialize($event));
        $this->assertNotSame($before, serialize($out));
        $this->assertNotSame($event, $out);
    }

    #[Group('ac-05-S11')]
    public function testCycleThrows(): void
    {
        $p = new \stdClass();
        $p->self = $p;

        $e = Thrown::by(\RangeException::class, fn(): mixed => Sanitizer::sanitize(new Event(parameters: $p)));
        $this->assertStringContainsString('Maximum call stack size exceeded', $e->getMessage());
    }

    #[Group('ac-05-S11')]
    public function testCycleInStructuredContentThrows(): void
    {
        $p = new \stdClass();
        $p->self = $p;

        $this->expectException(\RangeException::class);
        Sanitizer::sanitize(new Event(response: (object) ['structuredContent' => $p]));
    }

    #[Group('ac-05-S11')]
    public function testSharedNonCyclicObjectIsFine(): void
    {
        $shared = (object) ['v' => 1];
        $out = Sanitizer::sanitize(new Event(parameters: (object) ['a' => $shared, 'b' => $shared]));

        $this->assertEquals((object) ['a' => (object) ['v' => 1], 'b' => (object) ['v' => 1]], $out->parameters);
    }

    #[Group('ac-05-S11')]
    public function testDepthLimit(): void
    {
        $deep = static function (int $depth): mixed {
            $value = 'leaf';
            for ($i = 0; $i < $depth; ++$i) {
                $value = [$value];
            }

            return $value;
        };

        $ok = Sanitizer::sanitize(new Event(parameters: $deep(512)));
        $this->assertNotNull($ok->parameters);

        $e = Thrown::by(\RangeException::class, fn(): mixed => Sanitizer::sanitize(new Event(parameters: $deep(513))));
        $this->assertStringContainsString('Maximum call stack size exceeded', $e->getMessage());
    }

    #[Group('ac-05-S11')]
    public function testCycleIsDetectedOnTheBackEdge(): void
    {
        $thing = new class implements \JsonSerializable {
            public int $calls = 0;

            /** @return array<string, self> */
            #[\Override]
            public function jsonSerialize(): array
            {
                ++$this->calls;

                return ['self' => $this];
            }
        };

        try {
            Sanitizer::sanitize(new Event(parameters: $thing));
            self::fail('A cycle must throw');
        } catch (\RangeException) {
            $this->assertSame(1, $thing->calls);
        }
    }

    #[Group('ac-05-S11')]
    public function testDepthCountsThroughJsonSerializableAndObjects(): void
    {
        $wrap = static fn(mixed $payload): \JsonSerializable => new readonly class ($payload) implements \JsonSerializable {
            public function __construct(private mixed $payload) {}

            #[\Override]
            public function jsonSerialize(): mixed
            {
                return $this->payload;
            }
        };
        $arrays = static function (int $depth): mixed {
            $value = 'leaf';
            for ($i = 0; $i < $depth; ++$i) {
                $value = [$value];
            }

            return $value;
        };
        $objects = static function (int $depth): mixed {
            $value = 'leaf';
            for ($i = 0; $i < $depth; ++$i) {
                $value = (object) ['k' => $value];
            }

            return $value;
        };

        $this->assertNotNull(Sanitizer::sanitize(new Event(parameters: $wrap($arrays(511))))->parameters);
        $this->assertNotNull(Sanitizer::sanitize(new Event(parameters: $objects(512)))->parameters);
        $this->assertNotNull(Sanitizer::sanitize(new Event(response: (object) ['structuredContent' => $arrays(512)]))->response);

        foreach ([
            new Event(parameters: $wrap($arrays(512))),
            new Event(parameters: $objects(513)),
            new Event(response: (object) ['structuredContent' => $arrays(513)]),
        ] as $event) {
            try {
                Sanitizer::sanitize($event);
                self::fail('Nesting past 512 must throw');
            } catch (\RangeException $e) {
                $this->assertSame('Maximum call stack size exceeded', $e->getMessage());
            }
        }
    }

    #[Group('ac-05-S12')]
    public function testListResponseBecomesIndexKeyedObject(): void
    {
        $out = Sanitizer::sanitize(new Event(response: [Json::decode('{"type":"image"}')]));

        $this->assertInstanceOf(\stdClass::class, $out->response);
        $this->assertSame('{"0":{"type":"image"}}', Json::encodeJs($out->response));
    }

    public function testBothFieldsAreSanitizedAndIdentityFieldsStay(): void
    {
        $event = new Event(
            id: 'evt_1',
            sessionId: 'ses_1',
            projectId: 'proj_1',
            resourceName: 'tool',
            parameters: ['d' => self::b64(12000)],
            response: Json::decode('{"content":[{"type":"audio","data":"x"}]}'),
        );

        $out = Sanitizer::sanitize($event);

        $this->assertSame(['d' => self::BINARY_DATA], $out->parameters);
        $this->assertSame(self::AUDIO, JsonAssert::at($out->response, 'content', 0, 'text'));
        $this->assertSame(['evt_1', 'ses_1', 'proj_1', 'tool'], [$out->id, $out->sessionId, $out->projectId, $out->resourceName]);
    }

    public function testPhpObjectsInParameters(): void
    {
        $closure = static fn(): int => 1;
        $out = Sanitizer::sanitize(new Event(parameters: [
            'plain' => new PlainThing(),
            'ser' => new SerializableThing(),
            'fn' => $closure,
            'date' => $date = new \DateTimeImmutable('2020-01-01'),
        ]));

        $this->assertIsArray($out->parameters);
        $parameters = $out->parameters + ['plain' => null, 'ser' => null, 'fn' => null, 'date' => null];
        $this->assertInstanceOf(\stdClass::class, $parameters['plain']);
        $this->assertEquals((object) ['visible' => 'v', 'next' => null], $parameters['plain']);
        $this->assertSame(['kind' => 'thing', 'size' => 2], $parameters['ser']);
        $this->assertSame($closure, $parameters['fn']);
        $this->assertSame($date, $parameters['date']);
    }

    public function testJsonSerializableThatReturnsScalarIsSanitizedAsIs(): void
    {
        $thing = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): string
            {
                return str_repeat('A', 10240);
            }
        };

        $out = Sanitizer::sanitize(new Event(parameters: ['t' => $thing]));

        $this->assertSame(['t' => self::BINARY_DATA], $out->parameters);
    }

    public function testResponseObjectsAndNonObjects(): void
    {
        $this->assertSame('plain', Sanitizer::sanitize(new Event(response: 'plain'))->response);
        $date = new \DateTimeImmutable('2020-01-01');
        $this->assertSame($date, Sanitizer::sanitize(new Event(response: $date))->response);

        $out = Sanitizer::sanitize(new Event(response: new SerializableThing()));
        $this->assertEquals((object) ['kind' => 'thing', 'size' => 2], $out->response);

        $scalar = new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): string
            {
                return 'just text';
            }
        };
        $this->assertSame('just text', Sanitizer::sanitize(new Event(response: $scalar))->response);

        $out = Sanitizer::sanitize(new Event(response: ['content' => 'not a list', 'structuredContent' => 'str']));
        $this->assertEquals((object) ['content' => 'not a list', 'structuredContent' => 'str'], $out->response);
    }

    public function testStructuredContentNullAndInvalidUtf8(): void
    {
        $out = Sanitizer::sanitize(new Event(parameters: ['bad' => str_repeat("\xFF", 10240)], response: (object) ['structuredContent' => null]));

        $this->assertEquals((object) ['structuredContent' => null], $out->response);
        $this->assertSame(['bad' => str_repeat("\xFF", 10240)], $out->parameters);
    }
}
