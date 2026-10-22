<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Event;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Otlp\OtlpPayload;
use AgentCat\Internal\Telemetry\TelemetryManager;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OtlpExportTest extends TestCase
{
    private const string INIT = 'Initialized telemetry exporter: otlp';

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

    #[Group('ac-06-18')]
    public function testASuccessfulExportPostsTheSpanAndLogsTheEventId(): void
    {
        $config = new OtlpExporter('http://otlp.test', ['X-Key' => 'k']);
        $http = new RecordingHttpChannel();
        $event = ExporterFixtures::event();

        $this->export($http, $event, $config);

        $expected = OtlpPayload::of($config)->request($event);
        $this->assertCount(1, $http->requests);
        $this->assertSame($expected->url, $http->requests[0]->url);
        $this->assertSame($expected->headers, $http->requests[0]->headers);
        $this->assertSame($expected->body, $http->requests[0]->body);
        $this->assertSame([self::INIT, 'Successfully exported event to OTLP: ' . ExporterFixtures::EVENT], $this->logger->messages());
    }

    #[DataProvider('failures')]
    #[Group('ac-06-23')]
    public function testAFailedRequestRejectsWithTheTypeScriptText(HttpResponse $response, string $line): void
    {
        $this->export(new RecordingHttpChannel([$response]), ExporterFixtures::event());

        $this->assertSame([self::INIT, $line], $this->logger->messages());
    }

    /** @return iterable<string, array{HttpResponse, string}> */
    public static function failures(): iterable
    {
        yield 'a 404' => [new HttpResponse(404, 'Not Found', 'nf'), 'Telemetry export failed for otlp: OTLP export error: Error: OTLP export failed: 404 Not Found'];
        yield 'an HTTP/2 404 has no reason phrase (statusText "")' => [new HttpResponse(404, '', 'nf'), 'Telemetry export failed for otlp: OTLP export error: Error: OTLP export failed: 404 '];
        yield 'a 3xx is not ok' => [new HttpResponse(301, 'Moved Permanently', ''), 'Telemetry export failed for otlp: OTLP export error: Error: OTLP export failed: 301 Moved Permanently'];
        yield 'a network error (TS: the fetch rejection)' => [HttpResponse::failed('cURL error 7: Failed to connect'), 'Telemetry export failed for otlp: OTLP export error: cURL error 7: Failed to connect'];
    }

    /** D15, B04: a truthy duration that is not an integer fails like BigInt() and sends nothing. */
    #[DataProvider('fractionalDurations')]
    #[Group('ac-06-24')]
    public function testAFractionalDurationSendsNothingAndRejectsWithTheRangeError(float $duration, string $number): void
    {
        $http = new RecordingHttpChannel();
        $event = ExporterFixtures::event();
        $event->duration = $duration;

        $this->export($http, $event);

        $this->assertSame([], $http->requests);
        $this->assertSame([self::INIT, "Telemetry export failed for otlp: OTLP export error: RangeError: The number {$number} cannot be converted to a BigInt because it is not an integer"], $this->logger->messages());
    }

    /** @return iterable<string, array{float, string}> */
    public static function fractionalDurations(): iterable
    {
        yield '1.5' => [1.5, '1.5'];
        yield 'infinity' => [INF, 'Infinity'];
    }

    public function testAPayloadThatCannotBeEncodedSendsNothingAndRejectsWithThePhpError(): void
    {
        $http = new RecordingHttpChannel();
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $event = ExporterFixtures::event();
        $event->properties = $cycle;

        $this->export($http, $event);

        $this->assertSame([], $http->requests);
        $this->assertSame([self::INIT, 'Telemetry export failed for otlp: OTLP export error: UnexpectedValueException: Converting circular structure to JSON'], $this->logger->messages());
    }

    private function export(RecordingHttpChannel $http, Event $event, ?OtlpExporter $config = null): void
    {
        $manager = TelemetryManager::fromConfigs(['otlp' => $config ?? new OtlpExporter('http://otlp.test')], $http);
        $manager->export($event);
        $http->pump();
    }
}
