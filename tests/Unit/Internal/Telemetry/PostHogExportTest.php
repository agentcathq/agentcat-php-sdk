<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Event;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\PostHog\PostHogExport;
use AgentCat\Internal\Telemetry\PostHog\PostHogPayload;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PostHogExportTest extends TestCase
{
    private const string INIT = 'PostHogExporter: Initialized with endpoint https://us.i.posthog.com/batch';

    private const string SENDING_TWO = 'PostHogExporter: Sending 2 event(s) for ' . ExporterFixtures::EVENT;

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

    public function testTheConstructorLogsTheEndpoint(): void
    {
        new PostHogExport(PostHogPayload::of(new PostHogExporter('phc_x', 'https://eu.i.posthog.com/')), new RecordingHttpChannel());

        $this->assertSame(['PostHogExporter: Initialized with endpoint https://eu.i.posthog.com/batch'], $this->logger->messages());
    }

    #[Group('ac-06-40')]
    public function testASuccessfulExportPostsTheBatchAndLogsTheEventId(): void
    {
        $http = new RecordingHttpChannel();
        $event = ExporterFixtures::errorEvent();

        $this->export($http, $event);

        $payload = PostHogPayload::of(new PostHogExporter('phc_x'));
        $expected = $payload->request($payload->batch($event));
        $this->assertCount(1, $http->requests);
        $this->assertSame($expected->url, $http->requests[0]->url);
        $this->assertSame($expected->headers, $http->requests[0]->headers);
        $this->assertSame($expected->body, $http->requests[0]->body);
        $this->assertSame([self::INIT, self::SENDING_TWO, 'PostHog export success - Event: ' . ExporterFixtures::EVENT], $this->logger->messages());
    }

    #[DataProvider('failures')]
    public function testAFailedRequestLogsItsLine(HttpResponse $response, string $line): void
    {
        $this->export(new RecordingHttpChannel([$response]), ExporterFixtures::errorEvent());

        $this->assertSame([self::INIT, self::SENDING_TWO, $line], $this->logger->messages());
    }

    /** @return iterable<string, array{HttpResponse, string}> */
    public static function failures(): iterable
    {
        yield 'a 400' => [new HttpResponse(400, 'Bad Request', 'bad'), 'PostHog export failed - Status: 400, Body: bad'];
        yield 'a network error' => [HttpResponse::failed('cURL error 7: x'), 'PostHog export error: cURL error 7: x'];
    }

    /** ac-06-16: the exporter sends `parameters` and `response` as the event holds them (after the pipeline's redaction). */
    #[Group('ac-06-16')]
    public function testParametersAndResponseAreSentAsTheEventHoldsThem(): void
    {
        $http = new RecordingHttpChannel();
        $event = ExporterFixtures::event();
        $event->parameters = Json::decode('{"city":"[REDACTED]"}');
        $event->response = Json::decode('{"content":[{"type":"text","text":"[REDACTED]"}]}');

        $this->export($http, $event);

        $this->assertStringContainsString('"parameters":{"city":"[REDACTED]"},"response":{"content":[{"type":"text","text":"[REDACTED]"}]}', $http->requests[0]->body ?? '');
        $this->assertStringNotContainsString('London', $http->requests[0]->body ?? '');
    }

    public function testABatchThatCannotBeEncodedSendsNothingAndLogsTheError(): void
    {
        $http = new RecordingHttpChannel();
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $event = ExporterFixtures::event();
        $event->parameters = $cycle;

        $this->export($http, $event);

        $this->assertSame([], $http->requests);
        $this->assertSame([
            self::INIT,
            'PostHogExporter: Sending 1 event(s) for ' . ExporterFixtures::EVENT,
            'PostHog export error: UnexpectedValueException: Converting circular structure to JSON',
        ], $this->logger->messages(), 'TS logs the count before JSON.stringify');
    }

    private function export(RecordingHttpChannel $http, Event $event): void
    {
        $exporter = new PostHogExport(PostHogPayload::of(new PostHogExporter('phc_x')), $http);
        $exporter->export($event, static function (string $message): never {
            self::fail('PostHog logs its own failures and never rejects: ' . $message);
        });
        $http->pump();
    }
}
