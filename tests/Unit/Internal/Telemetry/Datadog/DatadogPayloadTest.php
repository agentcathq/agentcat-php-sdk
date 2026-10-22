<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry\Datadog;

use AgentCat\Event;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Telemetry\Datadog\DatadogPayload;
use AgentCat\Internal\Telemetry\JsError;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DatadogPayloadTest extends TestCase
{
    #[Group('ac-06-27')]
    public function testTagKeysAreSanitizedAndValuesLoseTheirCommas(): void
    {
        $this->assertStringEndsWith('agentcat.bad_key_x:a_b', $this->logString(ExporterFixtures::event(), 'ddtags'));
        $this->assertSame('a_b_c_d', DatadogPayload::tagKey("A\u{00A0}B,,C:D"));
        $this->assertSame('x_y', DatadogPayload::tagKey("X \t\n Y"));
        $this->assertSame('ünï_x', DatadogPayload::tagKey('ÜNÏ:x'));
        $this->assertSame('?', DatadogPayload::tagKey("\xFF"));
    }

    #[Group('ac-06-28')]
    public function testAZeroDurationHasNoDurationSeries(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = 0;

        $this->assertSame(1, self::requests($event)['series']);
        $this->assertSame(0, $this->log($event, 'mcp', 'duration_ms'));
        $this->assertSame(['mcp.events.count'], $this->metrics($event));
    }

    #[Group('ac-06-29')]
    public function testASessionlessEventGetsARandomDecimalTraceId(): void
    {
        $event = ExporterFixtures::event();
        $event->sessionId = '';

        $this->assertSame('', $this->log($event, 'mcp', 'session_id'));
        $this->assertMatchesRegularExpression('/\A[0-9]{1,20}\z/', $this->logString($event, 'dd', 'trace_id'));
    }

    public function testTheSiteBecomesTheIntakeHosts(): void
    {
        $payload = static fn(string $site): DatadogPayload => DatadogPayload::of(new DatadogExporter('k', $site, 's'));

        $this->assertSame('https://http-intake.logs.datadoghq.eu/api/v2/logs', $payload('https://datadoghq.eu/')->logsUrl);
        $this->assertSame('https://api.datadoghq.eu/api/v1/series', $payload('https://datadoghq.eu/')->metricsUrl);
        $this->assertSame('https://http-intake.logs.us3.datadoghq.com/api/v2/logs', $payload('us3.datadoghq.com')->logsUrl);
        $this->assertSame('https://api.us3.datadoghq.com/api/v1/series', $payload('us3.datadoghq.com')->metricsUrl);
        $this->assertSame('https://http-intake.logs.datadoghq.com//api/v2/logs', $payload('http://datadoghq.com//')->logsUrl);
        $this->assertSame('https://api.datadoghq.com//api/v1/series', $payload('datadoghq.com//')->metricsUrl);
        $this->assertSame('https://api.x.http://y/api/v1/series', $payload('x.http://y')->metricsUrl);
    }

    public function testANonStringTagValueIsATypeError(): void
    {
        $event = ExporterFixtures::event();
        $event->tags = ['n' => 5]; // @phpstan-ignore assign.propertyType (a redactEvent hook can put a non-string here)
        $error = Thrown::by(JsError::class, static fn(): mixed => self::requests($event));

        $this->assertSame('TypeError', $error->name);
        $this->assertSame('value.replace is not a function', $error->getMessage());
    }

    #[Group('ac-06-46')]
    public function testTheErrorMessageAndEmptyMaps(): void
    {
        $event = ExporterFixtures::event();
        $event->isError = true;
        $event->error = 'x';
        $event->tags = [];
        $event->properties = [];

        $this->assertSame('x', $this->log($event, 'error', 'message'));
        $this->assertEquals(new \stdClass(), $this->log($event, 'mcp', 'tags'));
        $this->assertEquals(new \stdClass(), $this->log($event, 'mcp', 'properties'));
        $this->assertSame('error', $this->log($event, 'status'));

        $event->error = (object) ['message' => 'boom'];
        $this->assertSame('{"message":"boom"}', $this->log($event, 'error', 'message'));

        $event->error = null;
        $this->assertSame(['message', 'service', 'ddsource', 'ddtags', 'timestamp', 'status', 'dd', 'mcp'], $this->keys($this->log($event)));
        $this->assertNotContains('error', $this->keys($this->log($event, 'mcp')));
    }

    public function testNullFieldsAreLeftOutOfTheMcpObject(): void
    {
        $event = ExporterFixtures::customEvent();

        $this->assertSame(['session_id', 'event_id', 'event_type', 'user_intent', 'client_name', 'client_version', 'server_name', 'server_version', 'is_error'], $this->keys($this->log($event, 'mcp')));
        $this->assertSame('agentcat:custom - unknown', $this->log($event, 'message'));
    }

    public function testEnvServiceAndFlagsShapeTheTags(): void
    {
        $event = ExporterFixtures::event();
        $event->tags = null;
        $event->resourceName = null;
        $event->isError = false;
        $event->eventType = '';
        $requests = DatadogPayload::of(new DatadogExporter('k', 'datadoghq.com', 'svc', 'prod'))->requests($event);
        $log = Json::decode($requests['logs']->body);

        $this->assertSame('env:prod,source:agentcat', JsonAssert::at($log, 0, 'ddtags'));
        $this->assertSame('unknown - unknown', JsonAssert::at($log, 0, 'message'));
        $this->assertSame('info', JsonAssert::at($log, 0, 'status'));
        $this->assertSame(['DD-API-KEY' => 'k', 'Content-Type' => 'application/json'], $requests['metrics']->headers);
        $this->assertSame(['service:svc', 'env:prod'], JsonAssert::at(Json::decode($requests['metrics']->body), 'series', 0, 'tags'));

        $noEnv = DatadogPayload::of(new DatadogExporter('k', 'datadoghq.com', 'svc', ''))->requests(ExporterFixtures::event());
        $this->assertStringContainsString('"ddtags":"event_type:mcp:tools.call,resource:get_weather,source:', $noEnv['logs']->body);
    }

    public function testSecondsRoundDownLikeMathFloor(): void
    {
        $event = ExporterFixtures::event();
        $event->timestamp = new \DateTimeImmutable('1969-12-31T23:59:59.500Z');

        $this->assertSame(-1, $this->series($event, 0, 'points', 0, 0));
        $this->assertSame(-500, $this->log($event, 'timestamp'));

        $event->timestamp = new \DateTimeImmutable('1969-12-31T23:59:59.000Z');
        $this->assertSame(-1, $this->series($event, 0, 'points', 0, 0));

        $event->timestamp = new \DateTimeImmutable('2025-06-15T12:00:05.999Z');
        $this->assertSame(1749988805, $this->series($event, 0, 'points', 0, 0));
    }

    public function testErrorEventsGetAnErrorCountSeries(): void
    {
        $this->assertSame(3, self::requests(ExporterFixtures::errorEvent())['series']);
        $this->assertSame(['mcp.events.count', 'mcp.event.duration', 'mcp.errors.count'], $this->metrics(ExporterFixtures::errorEvent()));
        $this->assertSame(1, self::requests(ExporterFixtures::customEvent())['series']);
    }

    /** @return array{logs: HttpRequest, metrics: HttpRequest, series: int} */
    private static function requests(Event $event): array
    {
        return DatadogPayload::of(new DatadogExporter('ddkey', 'datadoghq.com', 'svc'))->requests($event);
    }

    private function log(Event $event, string|int ...$path): mixed
    {
        return JsonAssert::at(Json::decode(self::requests($event)['logs']->body), 0, ...$path);
    }

    private function logString(Event $event, string|int ...$path): string
    {
        $value = $this->log($event, ...$path);
        $this->assertIsString($value);

        return $value;
    }

    private function series(Event $event, string|int ...$path): mixed
    {
        return JsonAssert::at(Json::decode(self::requests($event)['metrics']->body), 'series', ...$path);
    }

    /** @return list<mixed> */
    private function metrics(Event $event): array
    {
        $series = $this->series($event);
        $this->assertIsArray($series);

        return array_map(static fn(mixed $one): mixed => JsonAssert::at($one, 'metric'), array_values($series));
    }

    /** @return list<string> */
    private function keys(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }
}
