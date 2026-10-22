<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Telemetry\Datadog\DatadogPayload;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Telemetry\Otlp\OtlpPayload;
use AgentCat\Internal\Telemetry\PostHog\PostHogPayload;
use AgentCat\Internal\Telemetry\Sentry\SentryPayload;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\ExporterGolden;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ExporterGoldenTest extends TestCase
{
    #[Group('ac-06-18')]
    public function testOtlpSuccessMatchesTheGoldenRequest(): void
    {
        $golden = ExporterGolden::requests('otlp ok')[0] ?? throw new \LogicException('golden request missing');
        $request = OtlpPayload::of(new OtlpExporter('http://localhost:4318/', ['x-api-key' => 'k']))->request(ExporterFixtures::event());

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(get_object_vars($golden['headers']), $request->headers);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-20')]
    public function testOtlpErrorMatchesTheGoldenRequest(): void
    {
        $golden = ExporterGolden::requests('otlp err')[0] ?? throw new \LogicException('golden request missing');
        $request = OtlpPayload::of(new OtlpExporter('http://localhost:4318/v1/traces'))->request(ExporterFixtures::errorEvent());

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-25')]
    public function testDatadogSuccessMatchesTheGoldenRequests(): void
    {
        $golden = ExporterGolden::requests('datadog ok');
        $this->assertCount(2, $golden);
        $requests = DatadogPayload::of(new DatadogExporter('ddkey', 'https://datadoghq.eu/', 'svc', 'prod'))->requests(ExporterFixtures::event());

        $this->assertSame([$golden[0]['url'], $golden[1]['url']], [$requests['logs']->url, $requests['metrics']->url]);
        $this->assertSame(get_object_vars($golden[0]['headers']), $requests['logs']->headers);
        $this->assertSame(get_object_vars($golden[1]['headers']), $requests['metrics']->headers);
        $this->assertSame(ExporterGolden::normalized($golden[0]['body']), ExporterGolden::normalized($requests['logs']->body));
        $this->assertSame(ExporterGolden::normalized($golden[1]['body']), ExporterGolden::normalized($requests['metrics']->body));
        $this->assertSame(2, $requests['series']);
    }

    #[Group('ac-06-26')]
    public function testDatadogErrorMatchesTheGoldenRequests(): void
    {
        $golden = ExporterGolden::requests('datadog err');
        $this->assertCount(2, $golden);
        $requests = DatadogPayload::of(new DatadogExporter('ddkey', 'datadoghq.com', 'svc'))->requests(ExporterFixtures::errorEvent());

        $this->assertSame(ExporterGolden::normalized($golden[0]['body']), ExporterGolden::normalized($requests['logs']->body));
        $this->assertSame(ExporterGolden::normalized($golden[1]['body']), ExporterGolden::normalized($requests['metrics']->body));
        $this->assertSame(3, $requests['series']);
    }

    #[Group('ac-06-29')]
    public function testASessionlessOtlpEventMatchesTheGoldenRequestExceptTheRandomTraceId(): void
    {
        $golden = ExporterGolden::requests2('otlp')[0] ?? throw new \LogicException('golden request missing');
        $request = OtlpPayload::of(new OtlpExporter('http://c:4318'))->request(ExporterFixtures::listEvent());

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame($this->withoutTrace($golden['body']), $this->withoutTrace($request->body));
        $this->assertSame(1, preg_match('/"traceId":"[0-9a-f]{32}"/', $request->body));
    }

    #[Group('ac-06-29')]
    public function testASessionlessDatadogEventMatchesTheGoldenRequestsExceptTheRandomTraceId(): void
    {
        $golden = ExporterGolden::requests2('dd');
        $this->assertCount(2, $golden);
        $requests = DatadogPayload::of(new DatadogExporter('ddkey', 'us3.datadoghq.com', 'svc'))->requests(ExporterFixtures::listEvent());

        $this->assertSame([$golden[0]['url'], $golden[1]['url']], [$requests['logs']->url, $requests['metrics']->url]);
        $this->assertSame($this->withoutTrace($golden[0]['body']), $this->withoutTrace($requests['logs']->body));
        $this->assertSame(ExporterGolden::normalized($golden[1]['body']), ExporterGolden::normalized($requests['metrics']->body));
        $this->assertSame(1, preg_match('/"trace_id":"[0-9]{1,20}"/', $requests['logs']->body));
    }

    #[Group('ac-06-31')]
    #[Group('ac-06-34')]
    public function testSentryLogMatchesTheGoldenEnvelope(): void
    {
        $golden = ExporterGolden::requests('sentry ok');
        $payload = SentryPayload::of(new SentryExporter('https://abc123@o1.ingest.sentry.io/42', 'production', '1.2.3'));

        $this->assertCount(1, $golden);
        $this->assertSame($golden[0]['url'], $payload->endpoint);
        $this->assertSame(get_object_vars($golden[0]['headers']), $payload->headers());
        $envelope = $payload->logEnvelope(ExporterFixtures::event(), '2026-10-17T00:00:00.000Z');
        $this->assertStringEndsWith("\n", $envelope);
        $this->assertSame(ExporterGolden::maskSentry($golden[0]['body']), ExporterGolden::maskSentry($envelope));
    }

    #[Group('ac-06-32')]
    #[Group('ac-06-35')]
    public function testSentryErrorWithTracingMatchesTheGoldenEnvelopes(): void
    {
        $golden = ExporterGolden::requests('sentry err tracing');
        $payload = SentryPayload::of(new SentryExporter('https://abc123@sentry.example.com:9000/sub/path/42', enableTracing: true));
        $event = ExporterFixtures::errorEvent();
        $transaction = $payload->transaction($event);
        $error = $payload->errorEvent($event, $payload->transaction($event));

        $this->assertCount(3, $golden);
        $this->assertSame('https://sentry.example.com:9000/sub/path/api/42/envelope/', $payload->endpoint);
        $this->assertSame(ExporterGolden::maskSentry($golden[0]['body']), ExporterGolden::maskSentry($payload->logEnvelope($event, 'X')));
        $this->assertSame(ExporterGolden::maskSentry($golden[1]['body']), ExporterGolden::maskSentry($payload->transactionEnvelope($transaction, 'X')));
        $this->assertSame(ExporterGolden::maskSentry($golden[2]['body']), ExporterGolden::maskSentry($payload->errorEnvelope($error, 'X')));
        $this->assertStringEndsNotWith("\n", $payload->transactionEnvelope($transaction, 'X'));
        $trace = JsonAssert::at(Json::decode(ExportJson::encode($error['body'])), 'contexts', 'trace');
        $this->assertSame(JsonAssert::at($trace, 'span_id'), JsonAssert::at($trace, 'parent_span_id'), 'self-parent quirk');
        $this->assertMatchesRegularExpression('/\A38fbea6b36e16f37[0-9a-f]{16}\z/', $transaction->eventId);
    }

    #[Group('ac-06-29')]
    public function testASessionlessSentryLogMatchesTheGoldenEnvelopeExceptTheRandomTraceId(): void
    {
        $golden = ExporterGolden::requests2('sentry')[0] ?? throw new \LogicException('golden request missing');
        $payload = SentryPayload::of(new SentryExporter('https://abc123@o1.ingest.sentry.io/42'));
        $envelope = $payload->logEnvelope(ExporterFixtures::listEvent(), 'X');
        $mask = static fn(string $text): string => preg_replace('/"trace_id":"[0-9a-f]{32}"/', '"trace_id":"T"', ExporterGolden::maskSentry($text)) ?? '';

        $this->assertSame($golden['url'], $payload->endpoint);
        $this->assertSame(1, preg_match('/"trace_id":"[0-9a-f]{32}"/', $envelope));
        $this->assertSame($mask($golden['body']), $mask($envelope));
    }

    #[Group('ac-06-40')]
    public function testPostHogSuccessWithAiTracingMatchesTheGoldenBatch(): void
    {
        $golden = ExporterGolden::requests('posthog ok ai')[0] ?? throw new \LogicException('golden request missing');
        $payload = PostHogPayload::of(new PostHogExporter('phc_x', enableAITracing: true));
        $request = $payload->request($payload->batch(ExporterFixtures::event()));

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(get_object_vars($golden['headers']), $request->headers);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-41')]
    public function testPostHogErrorWithAiTracingMatchesTheGoldenBatch(): void
    {
        $golden = ExporterGolden::requests('posthog err ai')[0] ?? throw new \LogicException('golden request missing');
        $payload = PostHogPayload::of(new PostHogExporter('phc_x', 'https://eu.i.posthog.com/', true));
        $request = $payload->request($payload->batch(ExporterFixtures::errorEvent()));

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-42')]
    public function testPostHogCustomEventMatchesTheGoldenBatch(): void
    {
        $golden = ExporterGolden::requests('posthog custom')[0] ?? throw new \LogicException('golden request missing');
        $payload = PostHogPayload::of(new PostHogExporter('phc_x'));
        $request = $payload->request($payload->batch(ExporterFixtures::customEvent()));

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-29')]
    public function testASessionlessPostHogEventMatchesTheGoldenBatch(): void
    {
        $golden = ExporterGolden::requests2('posthog')[0] ?? throw new \LogicException('golden request missing');
        $payload = PostHogPayload::of(new PostHogExporter('phc'));
        $request = $payload->request($payload->batch(ExporterFixtures::listEvent()));

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    private function withoutTrace(string $json): string
    {
        $tree = Json::decode($json);
        $tree = self::replace($tree);

        return ExporterGolden::normalized(Json::encodeJs($tree) ?? '');
    }

    private static function replace(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(self::replace(...), $node);
        }
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $child) {
                $node->{$key} = ($key === 'traceId' || $key === 'trace_id') ? 'X' : self::replace($child);
            }
        }

        return $node;
    }
}
