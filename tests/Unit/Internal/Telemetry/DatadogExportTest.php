<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Event;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Datadog\DatadogPayload;
use AgentCat\Internal\Telemetry\TelemetryManager;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DatadogExportTest extends TestCase
{
    private const string LOGS = 'https://http-intake.logs.datadoghq.com/api/v2/logs';

    private const string METRICS = 'https://api.datadoghq.com/api/v1/series';

    private const array FIRST_LINES = [
        'Initialized telemetry exporter: dd',
        'DatadogExporter: Sending event immediately to Datadog',
        'DatadogExporter: Metrics URL: ' . self::METRICS,
    ];

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

    #[Group('ac-06-25')]
    public function testASuccessfulExportPostsTheLogThenTheMetricsAndLogsBothStatuses(): void
    {
        $http = new RecordingHttpChannel([], new HttpResponse(202, 'Accepted', '{}'));
        $event = ExporterFixtures::event();

        $this->export($http, $event);

        $expected = DatadogPayload::of($this->config())->requests($event);
        $this->assertSame([self::LOGS, self::METRICS], $http->urls());
        $this->assertCount(2, $http->requests);
        $this->assertSame($expected['logs']->headers, $http->requests[0]->headers);
        $this->assertSame($expected['logs']->body, $http->requests[0]->body);
        $this->assertSame($expected['metrics']->headers, $http->requests[1]->headers);
        $this->assertSame($expected['metrics']->body, $http->requests[1]->body);
        $this->assertSame([
            ...self::FIRST_LINES,
            'DatadogExporter: Sending 2 metric series',
            'Datadog logs success - Status: 202',
            'Datadog metrics success - Status: 202',
        ], $this->logger->messages());
    }

    #[Group('ac-06-26')]
    public function testAnErrorEventCountsItsThirdSeries(): void
    {
        $this->export(new RecordingHttpChannel(), ExporterFixtures::errorEvent());

        $this->assertContains('DatadogExporter: Sending 3 metric series', $this->logger->messages());
    }

    /** @param list<HttpResponse> $responses */
    #[DataProvider('failures')]
    #[Group('ac-06-30')]
    public function testAFailedRequestLogsItsLineAndTheOtherIsStillSent(array $responses, string $logs, string $metrics): void
    {
        $http = new RecordingHttpChannel($responses);

        $this->export($http, ExporterFixtures::event());

        $this->assertSame([self::LOGS, self::METRICS], $http->urls());
        $this->assertSame([...self::FIRST_LINES, 'DatadogExporter: Sending 2 metric series', $logs, $metrics], $this->logger->messages());
    }

    /** @return iterable<string, array{list<HttpResponse>, string, string}> */
    public static function failures(): iterable
    {
        yield 'logs network error' => [[HttpResponse::failed('cURL error 6: Could not resolve host')], 'Datadog logs network error: cURL error 6: Could not resolve host', 'Datadog metrics success - Status: 200'];
        yield 'logs 403' => [[new HttpResponse(403, 'Forbidden', 'denied')], 'Datadog logs failed - Status: 403', 'Datadog metrics success - Status: 200'];
        yield 'metrics network error' => [[new HttpResponse(200, 'OK', ''), HttpResponse::failed('cURL error 28: Operation timed out')], 'Datadog logs success - Status: 200', 'Datadog metrics network error: cURL error 28: Operation timed out'];
        yield 'metrics 500' => [[new HttpResponse(200, 'OK', ''), new HttpResponse(500, 'Internal Server Error', '')], 'Datadog logs success - Status: 200', 'Datadog metrics failed - Status: 500'];
    }

    /** TypeScript's payload building throws on a non-string tag value, before any request. */
    public function testANonStringTagValueSendsNothingAndRejectsWithTheTypeError(): void
    {
        $http = new RecordingHttpChannel();
        $event = ExporterFixtures::event();
        $event->tags = ['n' => 5]; // @phpstan-ignore assign.propertyType (a redactEvent hook can put a non-string there)

        $this->export($http, $event);

        $this->assertSame([], $http->requests);
        $this->assertSame([
            'Initialized telemetry exporter: dd',
            'DatadogExporter: Sending event immediately to Datadog',
            'Telemetry export failed for dd: value.replace is not a function',
        ], $this->logger->messages());
    }

    private function export(RecordingHttpChannel $http, Event $event): void
    {
        $manager = TelemetryManager::fromConfigs(['dd' => $this->config()], $http);
        $manager->export($event);
        $http->pump();
    }

    private function config(): DatadogExporter
    {
        return new DatadogExporter('k', 'datadoghq.com', 'svc');
    }
}
