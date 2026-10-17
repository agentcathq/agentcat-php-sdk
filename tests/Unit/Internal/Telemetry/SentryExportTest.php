<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Event;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\Sentry\SentryExport;
use AgentCat\Internal\Telemetry\Sentry\SentryPayload;
use AgentCat\Internal\Telemetry\TraceContext;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SentryExportTest extends TestCase
{
    private const string DSN = 'https://abc@o1.ingest.sentry.io/42';

    private const string ENDPOINT = 'https://o1.ingest.sentry.io/api/42/envelope/';

    private const string INIT = 'SentryExporter: Initialized with endpoint ' . self::ENDPOINT;

    private const string LOG_ITEM = '{"type":"log","item_count":1,"content_type":"application/vnd.sentry.items.log+json"}';

    private const string TRANSACTION_ITEM = '{"type":"transaction"}';

    private const string EVENT_ITEM = '{"type":"event","content_type":"application/json"}';

    /** `TraceContext::spanId()` of the fixture event: the first half of every Sentry `event_id` of that event. */
    private const string SPAN = '38fbea6b36e16f37';

    private const string ID = '/\A{"event_id":"([0-9a-f]{32})","sent_at":"\d{4}-\d\d\-\d\dT\d\d:\d\d:\d\d\.\d{3}Z"}\z/';

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

    #[Group('ac-06-31')]
    public function testTheConstructorLogsTheEndpoint(): void
    {
        $this->exporter(new RecordingHttpChannel());

        $this->assertSame([self::INIT], $this->logger->messages());
    }

    #[Group('ac-06-34')]
    public function testASuccessEventWithoutTracingSendsTheLogOnly(): void
    {
        $http = new RecordingHttpChannel();
        $event = ExporterFixtures::event();

        $this->export($http, $event);

        $this->assertCount(1, $http->requests);
        $request = $http->requests[0];
        $payload = SentryPayload::of(new SentryExporter(self::DSN));
        $this->assertSame(self::ENDPOINT, $request->url);
        $this->assertSame($payload->headers(), $request->headers);
        $this->assertSame($payload->logEnvelope($event, $this->sentAt($request)), $request->body);
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export success - Event: ' . ExporterFixtures::EVENT,
        ], $this->logger->messages());
    }

    #[Group('ac-06-35')]
    public function testAnErrorEventWithTracingSendsTheLogTheTransactionAndTheEventOneAfterTheOther(): void
    {
        $http = new RecordingHttpChannel();
        $exporter = $this->exporter($http, tracing: true);

        $exporter->export(ExporterFixtures::errorEvent(), $this->neverRejects());
        $this->assertCount(1, $http->requests, 'the transaction waits for the answer to the log');
        $http->pump();

        $this->assertSame([self::LOG_ITEM, self::TRANSACTION_ITEM, self::EVENT_ITEM], array_map($this->itemHeader(...), $http->requests));
        $this->assertSame([self::ENDPOINT, self::ENDPOINT, self::ENDPOINT], $http->urls());
        $this->assertCount(3, $http->requests);
        $transaction = $this->eventId($http->requests[1]);
        $error = $this->eventId($http->requests[2]);
        $this->assertNotSame($transaction, $error, 'the error event builds its own transaction (TS)');
        $this->assertSame(self::SPAN, TraceContext::spanId(ExporterFixtures::EVENT));
        $this->assertStringStartsWith(self::SPAN, $transaction);
        $this->assertStringStartsWith(self::SPAN, $error);
        $this->assertStringContainsString('"parent_span_id":"' . self::SPAN . '"', $http->requests[2]->body);
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export success - Event: ' . ExporterFixtures::EVENT,
            "SentryExporter: Sending transaction {$transaction} to Sentry",
            'Sentry transaction export success - Event: ' . ExporterFixtures::EVENT,
            "SentryExporter: Sending error event {$error} to Sentry for Issue creation",
            'Sentry error export success - Event: ' . ExporterFixtures::EVENT,
        ], $this->logger->messages());
    }

    #[Group('ac-06-36')]
    public function testAnErrorEventWithoutTracingSendsTheLogAndTheEvent(): void
    {
        $http = new RecordingHttpChannel();

        $this->export($http, ExporterFixtures::errorEvent());

        $this->assertSame([self::LOG_ITEM, self::EVENT_ITEM], array_map($this->itemHeader(...), $http->requests));
        $this->assertCount(2, $http->requests);
        $this->assertStringNotContainsString('parent_span_id', $http->requests[1]->body);
        $error = $this->eventId($http->requests[1]);
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export success - Event: ' . ExporterFixtures::EVENT,
            "SentryExporter: Sending error event {$error} to Sentry for Issue creation",
            'Sentry error export success - Event: ' . ExporterFixtures::EVENT,
        ], $this->logger->messages());
    }

    #[Group('ac-06-34')]
    public function testASuccessEventWithTracingSendsTheLogAndTheTransaction(): void
    {
        $http = new RecordingHttpChannel();

        $this->export($http, ExporterFixtures::event(), tracing: true);

        $this->assertSame([self::LOG_ITEM, self::TRANSACTION_ITEM], array_map($this->itemHeader(...), $http->requests));
    }

    #[Group('ac-06-39')]
    public function testANetworkErrorOnTheLogEndsTheSequence(): void
    {
        $http = new RecordingHttpChannel([HttpResponse::failed('cURL error 7: x')]);

        $this->export($http, ExporterFixtures::errorEvent(), tracing: true);

        $this->assertCount(1, $http->requests);
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry export error: cURL error 7: x',
        ], $this->logger->messages());
    }

    #[Group('ac-06-39')]
    public function testANetworkErrorOnTheTransactionEndsTheSequence(): void
    {
        $http = new RecordingHttpChannel([new HttpResponse(200, 'OK', ''), HttpResponse::failed('cURL error 28: y')]);

        $this->export($http, ExporterFixtures::errorEvent(), tracing: true);

        $this->assertSame([self::LOG_ITEM, self::TRANSACTION_ITEM], array_map($this->itemHeader(...), $http->requests));
        $this->assertCount(2, $http->requests);
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export success - Event: ' . ExporterFixtures::EVENT,
            'SentryExporter: Sending transaction ' . $this->eventId($http->requests[1]) . ' to Sentry',
            'Sentry export error: cURL error 28: y',
        ], $this->logger->messages());
    }

    public function testANonSuccessStatusLogsTheBodyAndTheSequenceGoesOn(): void
    {
        $http = new RecordingHttpChannel([
            new HttpResponse(500, 'Internal Server Error', 'oops'),
            new HttpResponse(502, '', 'bad gateway'),
            new HttpResponse(429, 'Too Many Requests', 'slow down'),
        ]);

        $this->export($http, ExporterFixtures::errorEvent(), tracing: true);

        $this->assertSame([self::LOG_ITEM, self::TRANSACTION_ITEM, self::EVENT_ITEM], array_map($this->itemHeader(...), $http->requests));
        $this->assertCount(3, $http->requests);
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export failed - Status: 500, Body: oops',
            'SentryExporter: Sending transaction ' . $this->eventId($http->requests[1]) . ' to Sentry',
            'Sentry transaction export failed - Status: 502, Body: bad gateway',
            'SentryExporter: Sending error event ' . $this->eventId($http->requests[2]) . ' to Sentry for Issue creation',
            'Sentry error export failed - Status: 429, Body: slow down',
        ], $this->logger->messages());
    }

    public function testATransactionThatCannotBeEncodedEndsTheSequenceWithTheCatchLine(): void
    {
        $http = new RecordingHttpChannel();

        $this->export($http, $this->cyclicEvent(), tracing: true);

        $this->assertSame([self::LOG_ITEM], array_map($this->itemHeader(...), $http->requests));
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export success - Event: ' . ExporterFixtures::EVENT,
            'Sentry export error: UnexpectedValueException: Converting circular structure to JSON',
        ], $this->logger->messages(), 'TS builds the envelope before its "Sending" line');
    }

    public function testAnErrorEventThatCannotBeEncodedEndsTheSequenceWithTheCatchLine(): void
    {
        $http = new RecordingHttpChannel();

        $this->export($http, $this->cyclicEvent());

        $this->assertSame([self::LOG_ITEM], array_map($this->itemHeader(...), $http->requests));
        $this->assertSame([
            self::INIT,
            'SentryExporter: Sending log for event ' . ExporterFixtures::EVENT . ' to Sentry',
            'Sentry log export success - Event: ' . ExporterFixtures::EVENT,
            'Sentry export error: UnexpectedValueException: Converting circular structure to JSON',
        ], $this->logger->messages(), 'TS builds the envelope before its "Sending" line');
    }

    private function cyclicEvent(): Event
    {
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $event = ExporterFixtures::errorEvent();
        $event->properties = $cycle; // only the transaction and the error event carry properties

        return $event;
    }

    private function export(RecordingHttpChannel $http, Event $event, bool $tracing = false): void
    {
        $this->exporter($http, $tracing)->export($event, $this->neverRejects());
        $http->pump();
    }

    private function exporter(RecordingHttpChannel $http, bool $tracing = false): SentryExport
    {
        return new SentryExport(SentryPayload::of(new SentryExporter(self::DSN, enableTracing: $tracing)), $http);
    }

    /** @return \Closure(string): void */
    private function neverRejects(): \Closure
    {
        return static function (string $message): never {
            self::fail('Sentry logs its own failures and never rejects: ' . $message);
        };
    }

    private function itemHeader(HttpRequest $request): string
    {
        return explode("\n", $request->body)[1] ?? '';
    }

    private function eventId(HttpRequest $request): string
    {
        $this->assertSame(1, preg_match(self::ID, explode("\n", $request->body)[0], $match));

        return $match[1];
    }

    private function sentAt(HttpRequest $request): string
    {
        $this->assertSame(1, preg_match('/"sent_at":"([^"]+)"/', $request->body, $match));

        return $match[1];
    }
}
