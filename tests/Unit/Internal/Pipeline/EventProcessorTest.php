<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Pipeline;

use AgentCat\Event;
use AgentCat\Internal\Errors\ExceptionCapture;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\EventProcessor;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Pipeline\Sanitizer;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Tags\TagValidator;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Samples\EndlessSerializable;
use AgentCat\Tests\Support\Samples\VisibilityThing;
use AgentCat\Tests\Support\Samples\WrappedValue;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class EventProcessorTest extends TestCase
{
    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-05-P1')]
    #[Group('ac-03-17')]
    #[Group('ac-05-P5')]
    #[Group('ac-01-53')]
    #[Group('ac-03-23')]
    public function testStagesRunInTypeScriptOrder(): void
    {
        $seen = [];
        $event = new Event(
            resourceName: str_repeat('r', 300),
            response: Json::decode('{"content":[{"type":"image","data":"iVBOR","mimeType":"image/png"}]}'),
        );
        $queued = new QueuedEvent(
            $event,
            static function (Event $e) use (&$seen): Event {
                $seen = [
                    'userIntent' => $e->userIntent,
                    'env' => $e->tags['env'] ?? null,
                    'id' => $e->id,
                    'type' => JsonAssert::at($e->response, 'content', 0, 'type'),
                ];
                $e->userIntent .= ' (reviewed)';

                return $e;
            },
            static fn(string $s): string => str_replace('secret', '[REDACTED]', $s),
            [
                static function (Event $e): void {
                    $e->userIntent = 'raw secret';
                    $e->tags = ['env' => 'prod'];
                },
            ],
        );

        $out = (new EventProcessor())->process($queued);

        $this->assertSame($event, $out);
        $this->assertSame(['userIntent' => 'raw secret', 'env' => 'prod', 'id' => '', 'type' => 'image'], $seen);
        $this->assertSame('raw [REDACTED] (reviewed)', $event->userIntent);
        $this->assertSame(Sanitizer::IMAGE_MARKER, JsonAssert::at($event->response, 'content', 0, 'text'));
        $this->assertSame(259, strlen((string) $event->resourceName));
        $this->assertMatchesRegularExpression('/\Aevt_[0-9A-Za-z]{27}\z/', $event->id);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-05-P2')]
    #[Group('ac-03-16')]
    #[Group('ac-01-49')]
    public function testHookReturningNullDropsOnlyThatEvent(): void
    {
        $processor = new EventProcessor();

        $this->assertNull($processor->process(new QueuedEvent(new Event(), static fn(Event $e): ?Event => null)));
        $this->assertSame(['Event dropped by redactEvent hook'], $this->logger->messages());
        $this->assertInstanceOf(Event::class, $processor->process(new QueuedEvent(new Event(userIntent: 'ok'))));
    }

    #[Group('ac-05-P3')]
    #[Group('ac-03-16')]
    #[Group('ac-01-50')]
    public function testThrowingHookDropsTheEvent(): void
    {
        $queued = new QueuedEvent(new Event(), static fn(Event $e): Event => throw new \RuntimeException('Hook failure'));

        $this->assertNull((new EventProcessor())->process($queued));
        $this->assertSame(['Failed to redact event (event-level hook): RuntimeException: Hook failure'], $this->logger->messages());
    }

    #[Group('ac-05-P4')]
    public function testRedactorFailureDropsTheEvent(): void
    {
        $queued = new QueuedEvent(new Event(userIntent: 'x'), null, static fn(string $s): string => throw new \RuntimeException('Redaction failed'));

        $this->assertNull((new EventProcessor())->process($queued));
        $this->assertSame(['Failed to redact event: RuntimeException: Redaction failed'], $this->logger->messages());
    }

    #[Group('ac-05-P4')]
    public function testSanitizeFailureDropsTheEvent(): void
    {
        $p = new \stdClass();
        $p->self = $p;

        $this->assertNull((new EventProcessor())->process(new QueuedEvent(new Event(parameters: $p))));
        $this->assertSame(['Failed to sanitize event: RangeException: Maximum call stack size exceeded'], $this->logger->messages());
    }

    #[Group('ac-05-P4')]
    public function testTruncateFailureDropsTheEvent(): void
    {
        $event = new Event(error: Json::decode('{"message":5}'));

        $this->assertNull((new EventProcessor())->process(new QueuedEvent($event)));
        $this->assertSame(['Failed to truncate event: UnexpectedValueException: error.message must be a string, got int'], $this->logger->messages());
    }

    #[Group('ac-05-R14')]
    public function testNonStringRedactorResultDropsTheEvent(): void
    {
        // @phpstan-ignore argument.type (a customer callback can return any type at runtime)
        $queued = new QueuedEvent(new Event(userIntent: 'x'), null, static fn(string $s): int => 5);

        $this->assertNull((new EventProcessor())->process($queued));
        $this->assertSame(['Failed to redact event: UnexpectedValueException: redactSensitiveInformation must return a string, got int'], $this->logger->messages());
    }

    #[Group('ac-05-P6')]
    #[Group('ac-05-P1')]
    public function testPendingFailureSkipsTheRestButTheEventContinues(): void
    {
        $hookRan = false;
        $event = new Event();
        $queued = new QueuedEvent(
            $event,
            static function (Event $e) use (&$hookRan): Event {
                $hookRan = true;

                return $e;
            },
            null,
            [
                static fn(Event $e) => throw new \RuntimeException('x'),
                static function (Event $e): void {
                    $e->userIntent = 'late';
                },
            ],
        );

        $out = (new EventProcessor())->process($queued);

        $this->assertSame($event, $out);
        $this->assertNull($event->userIntent);
        $this->assertTrue($hookRan);
        $this->assertMatchesRegularExpression('/\Aevt_/', $event->id);
        $this->assertSame([], $queued->pending);
        $this->assertSame(['Failed to resolve pending hook results: RuntimeException: x'], $this->logger->messages());
    }

    public function testHooksAreDetached(): void
    {
        $queued = new QueuedEvent(new Event(), static fn(Event $e): Event => $e, static fn(string $s): string => $s);

        (new EventProcessor())->process($queued);

        $this->assertNull($queued->redactEvent);
        $this->assertNull($queued->redactSensitiveInformation);
    }

    public function testAnExistingIdIsKept(): void
    {
        $id = 'evt_' . str_repeat('k', 27);
        $out = (new EventProcessor())->process(new QueuedEvent(new Event(id: $id)));

        $this->assertSame($id, $out?->id);
    }

    #[Group('ac-05-E19')]
    public function testErrorFieldsAreTruncated(): void
    {
        $error = ExceptionCapture::fromThrowable(new \RuntimeException(str_repeat('m', 3000)));
        $error->stack = str_repeat('s', 40000);
        $frame = JsonAssert::at($error, 'frames', 0);
        $this->assertInstanceOf(\stdClass::class, $frame);
        $frame->context_line = str_repeat('c', 40000);

        $out = (new EventProcessor())->process(new QueuedEvent(new Event(error: $error)));

        $this->assertInstanceOf(\AgentCat\Event::class, $out);
        $this->assertSame(2051, Js::utf16Length($this->text($out->error, 'message')));
        $this->assertSame(32771, Js::utf16Length($this->text($out->error, 'stack')));
        $this->assertSame(32771, Js::utf16Length($this->text($out->error, 'frames', 0, 'context_line')));
    }

    public function testEncodeFailureDropsTheEventWithoutThrowing(): void
    {
        $props = new \stdClass();
        $props->self = $props;

        $this->assertNull((new EventProcessor())->process(new QueuedEvent(new Event(properties: $props))));
        $this->assertSame(['Failed to truncate event: UnexpectedValueException: Converting circular structure to JSON'], $this->logger->messages());
    }

    public function testInvalidUtf8TagsSurviveTheWholePipeline(): void
    {
        $event = new Event(tags: TagValidator::validate(['k' => "caf\xE9"]));

        $out = (new EventProcessor())->process(new QueuedEvent($event));

        $this->assertInstanceOf(\AgentCat\Event::class, $out);
        $this->assertStringContainsString("caf\u{FFFD}", WireEncoder::encode($out));
    }

    public function testAnErrorFromAStageIsCaughtToo(): void
    {
        $p = ['x' => new class implements \JsonSerializable {
            #[\Override]
            public function jsonSerialize(): never
            {
                throw new \Error('not serializable');
            }
        }];

        $out = (new EventProcessor())->process(new QueuedEvent(new Event(properties: $p)));

        $this->assertNull($out);
        $this->assertSame(['Failed to truncate event: Error: not serializable'], $this->logger->messages());
    }

    /** @return iterable<string, array{array<string, mixed>, list<string|int>, (\Closure(string): string)|null}> fields, wire path of the object, redactor */
    public static function arrayCastProvider(): iterable
    {
        $array = (array) new VisibilityThing();
        $object = (object) (array) new VisibilityThing();
        $same = static fn(string $s): string => $s;

        yield 'properties' => [['properties' => $array], ['properties'], null];
        yield 'parameters' => [['parameters' => $array], ['parameters'], null];
        yield 'response' => [['response' => $array], ['response'], null];
        yield 'identifyActorData' => [['identifyActorData' => $array], ['identify_data'], null];
        yield 'error' => [['error' => $array], ['error'], null];
        yield 'properties object' => [['properties' => $object], ['properties'], null];
        yield 'parameters object' => [['parameters' => $object], ['parameters'], null];
        yield 'structuredContent object' => [['response' => ['structuredContent' => $object]], ['response', 'structuredContent'], null];
        yield 'parameters object with a redactor' => [['parameters' => $object], ['parameters'], $same];
        yield 'properties in the last resort' => [['properties' => ['blob' => str_repeat('x', 120000), 'o' => $object]], ['properties', 'o'], null];
    }

    /**
     * `(array) $object` keys of protected and private properties start with a NUL byte, which PHP refuses as a
     * property name. They are skipped like any non-public property, and the event is still sent.
     *
     * @param array<string, mixed> $fields
     * @param list<string|int> $path
     * @param (\Closure(string): string)|null $redactor
     */
    #[DataProvider('arrayCastProvider')]
    #[MaximumDuration(500)]
    public function testArrayCastOfAnObjectIsSentWithItsPublicPropertiesOnly(array $fields, array $path, ?\Closure $redactor): void
    {
        $event = new Event(projectId: 'proj_1');
        EventFields::assign($event, $fields);

        $out = (new EventProcessor())->process(new QueuedEvent($event, null, $redactor));

        $this->assertInstanceOf(Event::class, $out);
        $this->assertSame([], $this->logger->messages());
        $body = WireEncoder::encode($out);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('count', $body);
        $this->assertEquals(Json::decode('{"name":"n"}'), JsonAssert::at(Json::decode($body), ...$path));
    }

    /** @return iterable<string, array{string, string}> field, and the one line the stage that meets it first writes */
    public static function endlessFieldProvider(): iterable
    {
        // The sanitizer walks parameters before the truncator and has its own 512 cap.
        yield 'parameters' => ['parameters', 'Failed to sanitize event: RangeException: Maximum call stack size exceeded'];
        yield 'response' => ['response', 'Failed to truncate event: RangeException: Maximum call stack size exceeded'];
        yield 'identifyActorData' => ['identifyActorData', 'Failed to truncate event: RangeException: Maximum call stack size exceeded'];
        yield 'error' => ['error', 'Failed to truncate event: RangeException: Maximum call stack size exceeded'];
    }

    #[DataProvider('endlessFieldProvider')]
    public function testEndlessJsonSerializableDropsTheEventWithOneLine(string $field, string $line): void
    {
        $event = new Event();
        EventFields::assign($event, [$field => ['o' => new EndlessSerializable()]]);

        $this->assertNull((new EventProcessor())->process(new QueuedEvent($event)));
        $this->assertSame([$line], $this->logger->messages());
    }

    public function testLongJsonSerializableChainDropsTheEventWithOneLine(): void
    {
        $event = new Event(response: ['o' => WrappedValue::chain(40_000)]);

        $this->assertNull((new EventProcessor())->process(new QueuedEvent($event)));
        $this->assertSame(['Failed to truncate event: RangeException: Maximum call stack size exceeded'], $this->logger->messages());
    }

    /**
     * 16 000 strings of 200 units reach the last resort. Its cost was quadratic in the number of strings: 6.6 s on
     * the 4-core dev machine. It is linear now: 0.4 s, 0.5 s with pcov coverage. The 1.5 s bound is 3x the linear
     * time under coverage and 4x below the quadratic time, so a loaded machine (Infection runs one process per core)
     * stays under it, and a return to the quadratic cost does not.
     */
    #[Group('ac-05-T9')]
    #[MaximumDuration(1500)]
    public function testLastResortOverManyStringsIsFast(): void
    {
        $properties = [];
        for ($i = 0; $i < 16_000; ++$i) {
            $properties['k' . $i] = str_repeat('a b ', 50);
        }

        $start = hrtime(true);
        $out = (new EventProcessor())->process(new QueuedEvent(new Event(properties: $properties)));
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertInstanceOf(Event::class, $out);
        $this->assertSame(str_repeat('a b ', 13) . '...', JsonAssert::at($out->properties, 'k0'));
        $this->assertSame([], $this->logger->messages());
        $this->assertLessThan(1.5, $seconds);
    }

    private function text(mixed $value, string|int ...$path): string
    {
        $text = JsonAssert::at($value, ...$path);
        $this->assertIsString($text);

        return $text;
    }
}
