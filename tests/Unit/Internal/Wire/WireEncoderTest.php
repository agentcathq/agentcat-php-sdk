<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Wire;

use AgentCat\Event;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\WireEvents;
use PHPUnit\Framework\TestCase;

final class WireEncoderTest extends TestCase
{
    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $body = WireEncoder::encode(new Event(projectId: 'p', resourceName: 'a/b é'));

        $this->assertStringContainsString('"resource_name":"a/b é"', $body);
        $this->assertStringNotContainsString('\/', $body);
    }

    public function testInvalidUtf8IsReplacedEverywhere(): void
    {
        $properties = Json::decode('{}');
        $this->assertInstanceOf(\stdClass::class, $properties);
        $properties->{"k\xB1"} = 'v';
        $event = new Event(
            projectId: 'p',
            userIntent: "Incorrect string value: '\xE9t\xE9'",
            tags: ['k' => "caf\xE9"],
            properties: $properties,
        );

        $body = Json::decode(WireEncoder::encode($event));

        $this->assertInstanceOf(\stdClass::class, $body);
        $this->assertSame("caf\u{FFFD}", JsonAssert::at($body, 'tags', 'k'));
        $this->assertStringContainsString("\"k\u{FFFD}\":\"v\"", WireEncoder::encode($event));
        $this->assertSame("Incorrect string value: '\u{FFFD}t\u{FFFD}'", $body->user_intent);
    }

    public function testByteSizeIsEncodedLength(): void
    {
        $event = WireEvents::g1();

        $this->assertSame(strlen(WireEncoder::encode($event)), WireEncoder::byteSize($event));
        $this->assertGreaterThan(300, WireEncoder::byteSize($event));
    }

    public function testCircularPropertiesThrow(): void
    {
        $loop = new \stdClass();
        $loop->self = $loop;

        $this->expectException(\UnexpectedValueException::class);
        WireEncoder::encode(new Event(projectId: 'p', properties: $loop));
    }

    public function testLineTerminatorsAndZeroFractionsStayRaw(): void
    {
        $body = WireEncoder::encode(new Event(projectId: 'p', userIntent: "a\u{2028}b", properties: ['n' => 2.0]));

        $this->assertStringContainsString("a\u{2028}b", $body);
        $this->assertStringContainsString('"n":2', $body);
        $this->assertStringNotContainsString('2.0', $body);
    }
}
