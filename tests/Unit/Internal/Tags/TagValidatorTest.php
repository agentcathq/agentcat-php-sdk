<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Tags;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tags\TagValidator;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TagValidatorTest extends TestCase
{
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-01-42')]
    public function testEmptyInputsReturnNull(): void
    {
        $this->assertNull(TagValidator::validate([]));
        $this->assertNull(TagValidator::validate(new \stdClass()));
        $this->assertNull(TagValidator::validate(null));
        $this->assertNull(TagValidator::validate('env'));
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-42')]
    public function testValidTagsPassThrough(): void
    {
        $tags = ['env' => 'test', 'trace_id' => 'abc-123', 'a b$.:_-' => ''];
        $this->assertSame($tags, TagValidator::validate($tags));
        $this->assertSame(['env' => 'test'], TagValidator::validate((object) ['env' => 'test']));
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-42')]
    public function testInvalidKeysAreDropped(): void
    {
        $this->assertNull(TagValidator::validate(['' => 'x']));
        $this->assertNull(TagValidator::validate(['invalid!' => 'x']));
        $this->assertNull(TagValidator::validate(["a\n" => 'x']));
        $this->assertNull(TagValidator::validate(['é' => 'x']));
        $this->assertSame([
            'Dropping invalid tag: "" — key contains invalid characters or is empty',
            'Dropping invalid tag: "invalid!" — key contains invalid characters or is empty',
            "Dropping invalid tag: \"a\n\" — key contains invalid characters or is empty",
            'Dropping invalid tag: "é" — key contains invalid characters or is empty',
        ], $this->logger->messages());
    }

    #[Group('ac-01-42')]
    public function testKeyLengthLimit(): void
    {
        $ok = str_repeat('k', 32);
        $long = str_repeat('k', 33);
        $this->assertSame([$ok => 'v'], TagValidator::validate([$ok => 'v', $long => 'v']));
        $this->assertSame(['Dropping invalid tag: "' . $long . '" — key exceeds max length of 32'], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function nonStringProvider(): iterable
    {
        yield 'int' => [1, 'number'];
        yield 'float' => [1.5, 'number'];
        yield 'true' => [true, 'boolean'];
        yield 'false' => [false, 'boolean'];
        yield 'null' => [null, 'object'];
        yield 'list' => [['x'], 'object'];
        yield 'object' => [new \stdClass(), 'object'];
    }

    #[DataProvider('nonStringProvider')]
    #[Group('ac-01-43')]
    public function testNonStringValuesAreDropped(mixed $value, string $type): void
    {
        $this->assertNull(TagValidator::validate(['k' => $value]));
        $this->assertSame(['Dropping invalid tag: "k" — non-string value (got ' . $type . ')'], $this->logger->messages());
    }

    #[Group('ac-01-43')]
    public function testValueLengthCountsUtf16Units(): void
    {
        $ok = str_repeat('a', 200);
        $this->assertSame(['k' => $ok], TagValidator::validate(['k' => $ok]));
        $this->assertSame([], $this->logger->messages());

        $this->assertNull(TagValidator::validate(['k' => str_repeat('a', 201)]));
        $this->assertSame(['Dropping invalid tag: "k" — value exceeds max length of 200'], $this->logger->messages());

        $emoji100 = str_repeat('😀', 100);
        $this->assertSame(['k' => $emoji100], TagValidator::validate(['k' => $emoji100]));
        $this->assertNull(TagValidator::validate(['k' => str_repeat('😀', 101)]));
        $this->assertCount(2, $this->logger->messages());
    }

    #[Group('ac-01-43')]
    public function testNewlineRules(): void
    {
        $this->assertNull(TagValidator::validate(['k' => "a\nb"]));
        $this->assertSame(['Dropping invalid tag: "k" — value contains newline character'], $this->logger->messages());
        $this->assertSame(['k' => "a\rb"], TagValidator::validate(['k' => "a\rb"]));
    }

    #[Group('ac-01-43')]
    public function testChecksRunInSpecOrder(): void
    {
        // Key problems win over value problems; length wins over newline.
        TagValidator::validate(['bad!' => 1, 'k' => str_repeat("\n", 201)]);
        $this->assertSame([
            'Dropping invalid tag: "bad!" — key contains invalid characters or is empty',
            'Dropping invalid tag: "k" — value exceeds max length of 200',
        ], $this->logger->messages());
    }

    #[Group('ac-01-44')]
    public function testCapsAtFiftyEntries(): void
    {
        $tags = [];
        for ($i = 0; $i < 60; ++$i) {
            $tags['k' . $i] = 'v' . $i;
        }
        $result = TagValidator::validate($tags);
        $this->assertNotNull($result);
        $this->assertSame(array_slice($tags, 0, 50, true), $result);
        $this->assertSame(['Dropping 10 tag(s) — exceeds maximum of 50 entries per event'], $this->logger->messages());
    }

    #[Group('ac-01-44')]
    public function testFiftyExactlyIsNotCapped(): void
    {
        $tags = [];
        for ($i = 0; $i < 50; ++$i) {
            $tags['k' . $i] = 'v';
        }
        $this->assertCount(50, TagValidator::validate($tags) ?? []);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-44')]
    public function testCapCountsOnlyValidEntries(): void
    {
        $tags = ['bad!' => 'x'];
        for ($i = 0; $i < 51; ++$i) {
            $tags['k' . $i] = 'v';
        }
        $result = TagValidator::validate($tags);
        $this->assertCount(50, $result ?? []);
        $this->assertContains('Dropping 1 tag(s) — exceeds maximum of 50 entries per event', $this->logger->messages());
    }

    #[Group('ac-01-44')]
    public function testNumericStringKeyIsPreserved(): void
    {
        $result = TagValidator::validate(Json::decode('{"123":"a"}'));
        $this->assertNotNull($result);
        $this->assertSame(['123'], array_map(strval(...), array_keys($result)));
        $this->assertSame('{"123":"a"}', Json::encodeJs((object) $result));
    }

    #[Group('ac-01-44')]
    public function testIntegerLikeKeysComeFirstAscending(): void
    {
        $result = TagValidator::validate(Json::decode('{"b":"1","2":"x","a":"y","1":"z"}'));
        $this->assertNotNull($result);
        $this->assertSame(['1', '2', 'b', 'a'], array_map(strval(...), array_keys($result)));
        $this->assertSame('{"1":"z","2":"x","b":"1","a":"y"}', Json::encodeJs((object) $result));
    }

    #[Group('ac-01-44')]
    public function testPhpArrayIntegerKeysFollowJsOrdering(): void
    {
        $result = TagValidator::validate(['b' => '1', 10 => 'ten', 'a' => '2', 9 => 'nine', -1 => 'neg']);
        $this->assertNotNull($result);
        $this->assertSame(['9', '10', 'b', 'a', '-1'], array_map(strval(...), array_keys($result)));
    }

    #[Group('ac-01-44')]
    public function testIntegerLikeBoundary(): void
    {
        $result = TagValidator::validate(Json::decode('{"b":"x","4294967295":"big","4294967294":"max","007":"lead","0":"zero"}'));
        $this->assertNotNull($result);
        $this->assertSame(['0', '4294967294', 'b', '4294967295', '007'], array_map(strval(...), array_keys($result)));
    }
}
