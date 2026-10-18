<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration;

use AgentCat\Client;
use AgentCat\Identity;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Diagnostics through `Client::track()`: set up before the server is checked, the first call decides, sent at shutdown. */
final class ClientDiagnosticsTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string IGNORED = 'WARN: AgentCat diagnostics were set up by an earlier track(); disableDiagnostics: true in this call is ignored';

    private Environment $environment;

    /** `DIAGNOSTICS_ENDPOINT`: a closed local port (ruling P8). */
    private string $endpoint = '';

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('DISABLE_DIAGNOSTICS', 'DIAGNOSTICS_ENDPOINT', 'DIAGNOSTICS_TOKEN', 'AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'MCPCAT_API_URL', 'AGENTCAT_ENABLED', 'APP_ENV');
        $this->endpoint = MockCollector::closedPortUrl();
        putenv('DIAGNOSTICS_ENDPOINT=' . $this->endpoint); // fail closed: never otel.agentcat.com
        Diagnostics::reset();
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Diagnostics::reset();
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        EventQueue::setGlobal(null);
        Log::reset();
        $this->environment->restore();
    }

    #[Group('ac-06-47')]
    #[Group('ac-01-60')]
    public function testTrackSetsDiagnosticsUpBeforeTheCompatibilityCheck(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false'); // PHPUnit is a test environment: force it on, as a customer CI could
        $http = new RecordingHttpChannel();
        $queue = new EventQueue(transport: new RecordingTransport(), channel: $http);
        $client = new Client(ClientSeams::of($queue, static fn(): bool => true));

        $client->track(new \stdClass(), self::PROJECT); // an unsupported object: TS still sets diagnostics up first
        $queue->shutdown();

        $this->assertTrue(Diagnostics::enabled());
        $bodies = $this->recordBodies($http);
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('AgentCat SDK Compatibility: unrecognized server shape', $bodies[0]);
        $this->assertStringContainsString('Warning: Failed to track server - ', $bodies[1]);
    }

    #[Group('ac-06-52')]
    #[Group('ac-01-65')]
    public function testATestEnvironmentKeepsDiagnosticsOffByDefault(): void
    {
        $client = new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport(), channel: new RecordingHttpChannel()), static fn(): bool => true));
        $client->track(TrackingFixture::builder(), self::PROJECT);

        $this->assertFalse(Diagnostics::enabled());
    }

    #[Group('ac-06-53')]
    #[Group('ac-01-11')]
    public function testAFirstTrackWithDiagnosticsDisabledKeepsThemOff(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false');
        $client = $this->client(new RecordingHttpChannel());

        $client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableDiagnostics: true));
        $client->track(TrackingFixture::builder(), self::PROJECT);

        $this->assertFalse(Diagnostics::enabled());
        $this->assertNotContains(self::IGNORED, $this->logger->messages());
    }

    #[Group('ac-06-53')]
    #[Group('ac-01-11')]
    public function testALaterTrackCannotDisableDiagnosticsAndWarnsOnce(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false');
        $client = $this->client(new RecordingHttpChannel());

        $client->track(TrackingFixture::builder(), self::PROJECT);
        $client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableDiagnostics: true));
        $client->track(TrackingFixture::builder(), self::PROJECT, new Options(disableDiagnostics: true));

        $this->assertTrue(Diagnostics::enabled());
        $this->assertCount(1, array_keys($this->logger->messages(), self::IGNORED, true));
    }

    /** D44 and spec conflict 9: the enabled gate runs before the diagnostics setup. */
    public function testADisabledClientNeverSetsDiagnosticsUp(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false');
        $client = new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport(), channel: new RecordingHttpChannel()), static fn(): bool => false));

        $client->track(TrackingFixture::builder(), self::PROJECT);

        $this->assertFalse(Diagnostics::enabled());
    }

    /** `AgentCat::fake()`'s client: no telemetry, so no diagnostics either. */
    public function testWithoutTheTelemetrySeamNoDiagnosticsAreSetUp(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false');
        $client = new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport(), channel: new RecordingHttpChannel()), static fn(): bool => true, telemetry: false));

        $client->track(TrackingFixture::builder(), self::PROJECT);

        $this->assertFalse(Diagnostics::enabled());
    }

    /** D07 over 06 AC-61 (spec conflict 2): the per-event line carries the actor ID; no other line carries user data. */
    #[Group('ac-06-61')]
    #[MaximumDuration(5000)]
    public function testOnlyTheSentEventLineCarriesTheActorId(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false');
        $collector = MockCollector::start();

        try {
            $http = new RecordingHttpChannel();
            $queue = new EventQueue(transport: new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04])), channel: $http);
            $client = new Client(ClientSeams::of($queue, static fn(): bool => true));
            $builder = TrackingFixture::builder();
            Tools::echo($builder);
            $client->track($builder, self::PROJECT, new Options(
                identify: static fn(): Identity => new Identity('user-42@example.com', 'Ada Secret', ['plan' => 'top-secret-plan']),
                apiBaseUrl: $collector->scenario(),
            ));
            $harness = new HttpHarness($builder->build());
            $harness->initialize();
            $harness->callTool('echo', ['msg' => 'London-weather-arg', 'context' => 'context-text-why', 'session_id' => 'start']);

            $queue->shutdown();

            $lines = $this->recordBodies($http);
            $sent = array_values(array_filter($lines, static fn(string $line): bool => str_contains($line, 'Successfully sent event ')));
            $this->assertCount(1, $sent);
            $this->assertStringEndsWith(' ms | user-42@example.com', $sent[0]);
            foreach ($lines as $line) {
                foreach (['Ada Secret', 'top-secret-plan', 'London-weather-arg', 'context-text-why'] as $private) {
                    $this->assertStringNotContainsString($private, $line);
                }
                if ($line !== $sent[0]) {
                    $this->assertStringNotContainsString('user-42@example.com', $line);
                }
            }
        } finally {
            $collector->stop();
        }
    }

    /** The shutdown posts the buffer once, and a request that never completes cannot hold it past the budget. */
    #[Group('ac-06-62')]
    public function testShutdownSendsTheDiagnosticsOnceWithinItsBudget(): void
    {
        putenv('DISABLE_DIAGNOSTICS=false');
        $transport = new RecordingTransport(); // its clock is simulated: waits jump ahead, nothing sleeps
        $http = new RecordingHttpChannel(complete: false);
        $queue = new EventQueue(transport: $transport, shutdownTimeoutSeconds: 1.0, channel: $http);
        $client = new Client(ClientSeams::of($queue, static fn(): bool => true));
        $client->track(TrackingFixture::builder(), self::PROJECT);

        $queue->shutdown();

        $this->assertTrue(Diagnostics::enabled());
        $this->assertSame([$this->endpoint . '/v1/logs'], $http->urls());
        $this->assertLessThanOrEqual(1.0, $transport->now(), 'the shutdown ended at its 1 s deadline');
    }

    private function client(RecordingHttpChannel $http): Client
    {
        return new Client(ClientSeams::of(new EventQueue(transport: new RecordingTransport(), channel: $http), static fn(): bool => true));
    }

    /** @return list<string> the record bodies of every diagnostics request, in order */
    private function recordBodies(RecordingHttpChannel $http): array
    {
        $lines = [];
        foreach ($http->requests as $request) {
            if (!str_ends_with($request->url, '/v1/logs')) {
                continue;
            }
            $records = JsonAssert::at(Json::decode($request->body), 'resourceLogs', 0, 'scopeLogs', 0, 'logRecords');
            $this->assertIsList($records);
            foreach ($records as $record) {
                $text = JsonAssert::at($record, 'body', 'stringValue');
                $this->assertIsString($text);
                $lines[] = $text;
            }
        }

        return $lines;
    }
}
