<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry\Otlp;

use AgentCat\Event;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Telemetry\JsError;
use AgentCat\Internal\Telemetry\Otlp\OtlpPayload;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OtlpPayloadTest extends TestCase
{
    #[Group('ac-06-19')]
    public function testTheUrlEndsInV1TracesOnce(): void
    {
        $this->assertSame('http://h:4318/v1/traces', OtlpPayload::of(new OtlpExporter('http://h:4318/v1/traces//'))->url);
        $this->assertSame('http://h:4318/v1/traces', OtlpPayload::of(new OtlpExporter('http://h:4318'))->url);
        $this->assertSame('http://h:4318/v1/traces', OtlpPayload::of(new OtlpExporter('http://h:4318/'))->url);
        $this->assertSame('http://h/x/v1/traces', OtlpPayload::of(new OtlpExporter('http://h/x'))->url);
    }

    #[Group('ac-06-19')]
    public function testAUserContentTypeReplacesTheDefault(): void
    {
        $payload = OtlpPayload::of(new OtlpExporter('http://h', ['Content-Type' => 'application/x-protobuf', 'x-a' => 'b']));

        $this->assertSame(['Content-Type' => 'application/x-protobuf', 'x-a' => 'b'], $payload->headers);
        $this->assertSame(['Content-Type' => 'application/json'], OtlpPayload::of(new OtlpExporter('http://h'))->headers);
    }

    #[Group('ac-06-21')]
    public function testAZeroOrMissingDurationEndsWhereTheSpanStarts(): void
    {
        foreach ([0, null, 0.0] as $duration) {
            $event = ExporterFixtures::event();
            $event->duration = $duration;
            $this->assertSame('1749988805123000000', $this->span($event, 'startTimeUnixNano'));
            $this->assertSame('1749988805123000000', $this->span($event, 'endTimeUnixNano'));
        }
    }

    #[Group('ac-06-22')]
    public function testFalsyAttributesAreLeftOut(): void
    {
        $event = ExporterFixtures::event();
        $event->userIntent = '';
        $event->tags = ['empty' => '', 'full' => 'x'];
        $keys = array_keys(self::attributes($event));

        $this->assertNotContains('mcp.user_intent', $keys);
        $this->assertNotContains('agentcat.tag.empty', $keys);
        $this->assertContains('agentcat.tag.full', $keys);
    }

    #[Group('ac-06-22')]
    public function testPropertiesAreAJsonStringAttribute(): void
    {
        $event = ExporterFixtures::event();
        $event->properties = new \stdClass();
        $this->assertSame(['agentcat.properties' => '{}'], array_intersect_key($this->attributes($event), ['agentcat.properties' => 1]));

        $event->properties = ['a' => 1];
        $this->assertSame(['agentcat.properties' => '{"a":1}'], array_intersect_key($this->attributes($event), ['agentcat.properties' => 1]));

        $event->properties = null;
        $this->assertArrayNotHasKey('agentcat.properties', $this->attributes($event));
    }

    #[Group('ac-06-24')]
    public function testAFractionalDurationIsARangeError(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = 1.5;
        $error = Thrown::by(JsError::class, static fn(): mixed => OtlpPayload::of(new OtlpExporter('http://h'))->request($event));

        $this->assertSame('RangeError', $error->name);
        $this->assertSame('The number 1.5 cannot be converted to a BigInt because it is not an integer', $error->getMessage());

        $event->duration = INF;
        $error = Thrown::by(JsError::class, static fn(): mixed => OtlpPayload::of(new OtlpExporter('http://h'))->request($event));
        $this->assertSame('The number Infinity cannot be converted to a BigInt because it is not an integer', $error->getMessage());

        $event->duration = -INF;
        $error = Thrown::by(JsError::class, static fn(): mixed => OtlpPayload::of(new OtlpExporter('http://h'))->request($event));
        $this->assertSame('The number -Infinity cannot be converted to a BigInt because it is not an integer', $error->getMessage());
    }

    #[Group('ac-06-24')]
    public function testNanIsFalsyAndAWholeFloatIsExact(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = NAN;
        $this->assertSame('1749988805123000000', $this->span($event, 'endTimeUnixNano'));

        $event->duration = 150.0;
        $this->assertSame('1749988805273000000', $this->span($event, 'endTimeUnixNano'));

        $event->duration = 150;
        $this->assertSame('1749988805273000000', $this->span($event, 'endTimeUnixNano'));
    }

    public function testEndNanosEdges(): void
    {
        $this->assertSame('1749988805118000000', OtlpPayload::endNanos(1749988805123, -5));
        $this->assertSame('0', OtlpPayload::endNanos(0, 0));
        $this->assertSame('0', OtlpPayload::endNanos(5, -5));
        $this->assertSame('0', OtlpPayload::endNanos(9_000_000_000_000_000, -9.0e15));
        $this->assertSame('1749988805123000000', OtlpPayload::endNanos(1749988805123, null));
        $this->assertSame('1749988805123000000', OtlpPayload::endNanos(1749988805123, -0.0));
    }

    public function testEndNanosFallsBackToFloatArithmeticBeyondTheIntRange(): void
    {
        $this->assertSame('9223373786843580416000000', OtlpPayload::endNanos(1749988805123, PHP_INT_MAX));
        $this->assertSame('9000000000000000000000', OtlpPayload::endNanos(0, 9.0e15));
        $this->assertSame('-9000000000000000000000', OtlpPayload::endNanos(0, -9.0e15));
        $this->assertSame('9223372036854775808000000', OtlpPayload::endNanos(0, 9223372036854775808.0));
        $this->assertSame('-9223372036854775808000000', OtlpPayload::endNanos(0, -9223372036854775808.0));
        $this->assertSame('4000001749988805123000000', OtlpPayload::endNanos(1749988805123, 4.0e18));
        $this->assertMatchesRegularExpression('/\A[0-9]+000000\z/', OtlpPayload::endNanos(1749988805123, 1.0e300));
    }

    public function testStatusCode(): void
    {
        $event = ExporterFixtures::event();
        $event->isError = true;
        $this->assertSame(2, $this->span($event, 'status', 'code'));

        $event->isError = false;
        $this->assertSame(1, $this->span($event, 'status', 'code'));

        $event->isError = null;
        $this->assertSame(1, $this->span($event, 'status', 'code'));
    }

    public function testDefaultsForMissingServerAndVersion(): void
    {
        $event = ExporterFixtures::event();
        $event->agentcatVersion = null;
        $event->serverName = null;
        $event->serverVersion = null;

        $this->assertSame('unknown', $this->resourceField($event, 'scopeSpans', 0, 'scope', 'version'));
        $this->assertSame('mcp-server', $this->resourceField($event, 'resource', 'attributes', 0, 'value', 'stringValue'));
        $this->assertSame('unknown', $this->resourceField($event, 'resource', 'attributes', 1, 'value', 'stringValue'));
        $this->assertSame('agentcat', $this->resourceField($event, 'scopeSpans', 0, 'scope', 'name'));
    }

    public function testAnEmptyEventTypeNamesTheSpanMcpEvent(): void
    {
        $event = ExporterFixtures::event();
        $event->eventType = '';

        $this->assertSame('mcp.event', $this->span($event, 'name'));
        $this->assertSame('mcp:tools/call', $this->span(ExporterFixtures::event(), 'name'));
        $this->assertSame(2, $this->span(ExporterFixtures::event(), 'kind'));
    }

    public function testTheRequestCarriesTheConfiguredUrlAndHeaders(): void
    {
        $request = OtlpPayload::of(new OtlpExporter('http://h', ['k' => 'v']))->request(ExporterFixtures::event());

        $this->assertSame('http://h/v1/traces', $request->url);
        $this->assertSame(['Content-Type' => 'application/json', 'k' => 'v'], $request->headers);
    }

    private function span(Event $event, string|int ...$path): mixed
    {
        return JsonAssert::at(Json::decode(OtlpPayload::of(new OtlpExporter('http://h'))->request($event)->body), 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0, ...$path);
    }

    private function resourceField(Event $event, string|int ...$path): mixed
    {
        return JsonAssert::at(Json::decode(OtlpPayload::of(new OtlpExporter('http://h'))->request($event)->body), 'resourceSpans', 0, ...$path);
    }

    /** @return array<string, mixed> */
    private function attributes(Event $event): array
    {
        $attributes = $this->span($event, 'attributes');
        $this->assertIsArray($attributes);
        $map = [];
        foreach ($attributes as $attribute) {
            $key = JsonAssert::at($attribute, 'key');
            $this->assertIsString($key);
            $map[$key] = JsonAssert::at($attribute, 'value', 'stringValue');
        }

        return $map;
    }
}
