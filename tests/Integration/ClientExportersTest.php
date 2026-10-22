<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration;

use AgentCat\Client;
use AgentCat\Event;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\RetryPolicy;
use AgentCat\Options;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Exporters through `Client::track()` on real mcp/sdk Builders. */
final class ClientExportersTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string NO_PROJECT = 'Warning: No projectId provided and no exporters configured. Events will not be sent anywhere.';

    private Environment $environment;

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private RecordingHttpChannel $http;

    private EventQueue $queue;

    private Client $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_ENABLED', 'APP_ENV');
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->http = new RecordingHttpChannel();
        $this->queue = new EventQueue(transport: $this->transport, channel: $this->http);
        $this->client = new Client(ClientSeams::of($this->queue, static fn(): bool => true));
    }

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        EventQueue::setGlobal(null);
        Log::reset();
        $this->environment->restore();
    }

    #[Group('ac-06-14')]
    #[Group('ac-01-5')]
    public function testTelemetryOnlyTrackingExportsAndNeverCallsTheApi(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, '', new Options(exporters: ['otlp' => new OtlpExporter('http://otlp.test')]));
        $this->call($builder);

        $this->assertTrue($this->queue->flush());

        $this->assertSame([], $this->transport->deliveries);
        $this->assertSame(['http://otlp.test/v1/traces'], $this->http->urls());
        $this->assertNotContains(self::NO_PROJECT, $this->logger->messages());
        $this->assertContains('AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=1', $this->logger->messages());
        $this->assertSame(['Initialized telemetry exporter: otlp', 'Initialized telemetry with 1 exporters'], $this->initializedLines());
    }

    #[Group('ac-06-13')]
    #[Group('ac-01-10')]
    public function testOnlyTheFirstExportersReceiveEvents(): void
    {
        $first = TrackingFixture::builder();
        $second = TrackingFixture::builder();
        Tools::echo($first);
        Tools::echo($second);
        $this->client->track($first, self::PROJECT, new Options(exporters: ['a' => new OtlpExporter('http://a.test')]));
        $this->client->track($second, self::PROJECT, new Options(exporters: ['b' => new OtlpExporter('http://b.test')]));
        $this->call($first);
        $this->call($second);

        $this->assertTrue($this->queue->flush());

        $this->assertSame(['http://a.test/v1/traces', 'http://a.test/v1/traces'], $this->http->urls());
        $this->assertContains('WARN: AgentCat exporters were set up by an earlier track(); the exporters of this call are ignored', $this->logger->messages());
        $this->assertContains('AgentCat setup complete | project ' . self::PROJECT . ' | tracing=true context=true reportMissing=true exporters=1', $this->logger->messages());
    }

    /** Review Focus 3: a new Builder per request in a long-running worker, the same exporters every time. */
    public function testPerRequestBuildersWithTheSameExportersShareOneManager(): void
    {
        for ($request = 0; $request < 3; ++$request) {
            $builder = TrackingFixture::builder();
            Tools::echo($builder);
            $this->client->track($builder, self::PROJECT, new Options(exporters: ['otlp' => new OtlpExporter('http://otlp.test')]));
            $this->call($builder);
        }

        $this->assertTrue($this->queue->flush());

        $this->assertCount(3, $this->http->requests);
        $this->assertCount(1, array_keys($this->logger->messages(), 'Initialized telemetry with 1 exporters', true));
        $this->assertSame([], array_values(array_filter($this->logger->messages(), static fn(string $l): bool => str_starts_with($l, 'WARN: AgentCat exporters'))));
    }

    #[Group('ac-06-16')]
    public function testPostHogGetsTheRedactedParameters(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT, new Options(
            redactSensitiveInformation: static fn(string $text): string => $text === 'secret' ? '[R]' : $text,
            exporters: ['ph' => new PostHogExporter('phc_x')],
        ));
        $this->call($builder, ['msg' => 'secret']);

        $this->assertTrue($this->queue->flush());

        $request = $this->http->requests[0] ?? null;
        $this->assertInstanceOf(\AgentCat\Internal\Transport\HttpRequest::class, $request);
        $body = Json::decode($request->body);
        $this->assertSame('[R]', JsonAssert::at($body, 'batch', 0, 'properties', 'parameters', 'request', 'params', 'arguments', 'msg'));
    }

    #[Group('ac-06-15')]
    public function testAnEventDroppedByRedactEventIsNotExported(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT, new Options(
            redactEvent: static fn(Event $event): ?Event => null,
            exporters: ['otlp' => new OtlpExporter('http://otlp.test')],
        ));
        $this->call($builder);

        $this->assertTrue($this->queue->flush());

        $this->assertSame([], $this->http->requests);
        $this->assertSame([], $this->transport->deliveries);
    }

    public function testEmptyExportersKeepTheTypeScriptEmptyObjectSemantics(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, '', new Options(exporters: []));

        $this->assertNotContains(self::NO_PROJECT, $this->logger->messages());
        $this->assertSame(['Initialized telemetry with 0 exporters'], $this->initializedLines());
        $this->assertContains('AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=0', $this->logger->messages());
    }

    public function testNullExportersKeepTheNoProjectWarningAndSetNothingUp(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, '');

        $this->assertContains(self::NO_PROJECT, $this->logger->messages());
        $this->assertSame([], $this->initializedLines());
    }

    public function testASecondTrackOfATrackedServerNeverTouchesTheExporters(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT);
        $this->client->track($builder, self::PROJECT, new Options(exporters: ['otlp' => new OtlpExporter('http://otlp.test')]));

        $this->assertSame([], $this->initializedLines());
        $this->assertSame([], array_values(array_filter($this->logger->messages(), static fn(string $l): bool => str_starts_with($l, 'WARN: AgentCat exporters'))));
        $this->call($builder);
        $this->assertTrue($this->queue->flush());
        $this->assertSame([], $this->http->requests);
    }

    public function testASecondTrackOfATrackedServerWithOtherExportersLogsNoWarning(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->client->track($builder, self::PROJECT, new Options(exporters: ['a' => new OtlpExporter('http://a.test')]));
        $this->client->track($builder, self::PROJECT, new Options(exporters: ['b' => new DatadogExporter('k', 'datadoghq.com', 'svc')]));

        $this->assertSame(['Initialized telemetry exporter: a', 'Initialized telemetry with 1 exporters'], $this->initializedLines());
        $this->assertSame([], array_values(array_filter($this->logger->messages(), static fn(string $l): bool => str_starts_with($l, 'WARN: AgentCat exporters'))));
    }

    /** B03 parity, D15: the exports run once per API attempt, so three failed attempts give four exports. */
    #[Group('ac-06-17')]
    #[MaximumDuration(5000)]
    public function testExportsRunOncePerApiAttempt(): void
    {
        $collector = MockCollector::start();

        try {
            $base = $collector->scenario(array_fill(0, 4, ['status' => 503]));
            $queue = new EventQueue(transport: new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04])), channel: $this->http);
            $client = new Client(ClientSeams::of($queue, static fn(): bool => true));
            $builder = TrackingFixture::builder();
            Tools::echo($builder);
            $client->track($builder, self::PROJECT, new Options(apiBaseUrl: $base, exporters: ['otlp' => new OtlpExporter('http://otlp.test')]));
            $this->call($builder);

            $this->assertTrue($client->flush(5.0));

            $this->assertCount(4, $collector->requests($base));
            $this->assertCount(4, $this->http->requests);
            $spanIds = array_map(
                static fn(\AgentCat\Internal\Transport\HttpRequest $request): mixed => JsonAssert::at(Json::decode($request->body), 'resourceSpans', 0, 'scopeSpans', 0, 'spans', 0, 'spanId'),
                $this->http->requests,
            );
            $this->assertIsString($spanIds[0]);
            $this->assertSame(array_fill(0, 4, $spanIds[0]), $spanIds);
            $retries = array_filter($this->logger->messages(), static fn(string $l): bool => str_starts_with($l, 'Failed to send event ') && str_contains($l, ', retrying... [Error: HTTP 503]'));
            $this->assertCount(4, $retries);
        } finally {
            $collector->stop();
        }
    }

    /** A `Client` built without seams (what a container autowires) sets exporters up on the process queue. */
    public function testTheDefaultClientSetsExportersUpOnTheGlobalQueue(): void
    {
        putenv('AGENTCAT_ENABLED=true');
        EventQueue::setGlobal($this->queue);
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        (new Client())->track($builder, self::PROJECT, new Options(exporters: ['otlp' => new OtlpExporter('http://otlp.test')]));
        $this->call($builder);

        $this->assertTrue($this->queue->flush());

        $this->assertSame(['http://otlp.test/v1/traces'], $this->http->urls());
        $this->assertContains('Initialized telemetry with 1 exporters', $this->logger->messages());
    }

    public function testWithoutTheTelemetrySeamNoExportersAreSetUp(): void
    {
        $client = new Client(ClientSeams::of($this->queue, static fn(): bool => true, telemetry: false));
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $client->track($builder, '', new Options(exporters: ['otlp' => new OtlpExporter('http://otlp.test')]));
        $this->call($builder);

        $this->assertTrue($this->queue->flush());

        $this->assertSame([], $this->initializedLines());
        $this->assertSame([], $this->http->requests);
        $this->assertContains('AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=1', $this->logger->messages());
    }

    /** @return list<string> */
    private function initializedLines(): array
    {
        return array_values(array_filter($this->logger->messages(), static fn(string $l): bool => str_starts_with($l, 'Initialized')));
    }

    /** @param array<string, string> $arguments */
    private function call(Builder $builder, array $arguments = ['msg' => 'hi']): void
    {
        $harness = new HttpHarness($builder->build()); // not TrackingFixture: its constructor replaces the logger
        $harness->initialize();
        $harness->callTool('echo', $arguments + ['context' => 'why', 'session_id' => 'start']);
    }
}
