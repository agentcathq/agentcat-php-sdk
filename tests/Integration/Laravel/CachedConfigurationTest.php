<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\EventApiGuard;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use Illuminate\Container\Container;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * An application with `php artisan config:cache` (plan E1 header conflict 12): the configuration holds what `.env`
 * gave when the cache was made, and the process holds none of it.
 */
final class CachedConfigurationTest extends BridgeTestCase
{
    private const string SESSION = 'ses_ccccccccccccccccccccccccccc';

    private const string PUBLISHED = "Published custom event for session ses_ccccccccccccccccccccccccccc with type 'agentcat:custom'";

    /** What `.env` held when the cache was made: AgentCat on, and the project. */
    private const array DOT_ENV = ['AGENTCAT_ENABLED' => 'true', 'AGENTCAT_PROJECT_ID' => LaravelTrackingFixture::PROJECT];

    private MemoryLogger $logger;

    /** The diagnostics variables of the process; a test turns diagnostics on as a platform variable can. */
    private ?Environment $diagnosticsEnvironment = null;

    /** The collector `.env` names for diagnostics: a closed local port (ruling P8). */
    private string $cachedCollector = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->diagnosticsEnvironment = Environment::clear('AGENTCAT_DISABLE_DIAGNOSTICS', 'DISABLE_DIAGNOSTICS', 'AGENTCAT_DIAGNOSTICS_TOKEN', 'MCPCAT_API_URL', 'APP_ENV');
        EventApiGuard::set(); // tests/bootstrap.php's closed event API again, after the clear
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=true'); // tests/bootstrap.php's value: a test that wants diagnostics says so
        $this->cachedCollector = MockCollector::closedPortUrl() . '/cached';
        Diagnostics::reset(); // the first init() of the process decides: a test of another class can have made it
        parent::setUp();
        Log::setLogger($this->logger = new MemoryLogger()); // nothing goes to agentcat.log in the test HOME
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->diagnosticsEnvironment?->restore();
    }

    /** `.env` sets the project; the published file maps the server to `env('AGENTCAT_BILLING_PROJECT_ID')`, which it does not set. */
    public function defineANullProjectEntry(): void
    {
        self::cachedConfiguration(['AGENTCAT_ENABLED' => 'true', 'AGENTCAT_PROJECT_ID' => LaravelTrackingFixture::PROJECT], ['projects' => [ConformanceServer::class => null]]);
    }

    /**
     * `.env` turns AgentCat off. PHPUnit makes AgentCat off by default, so the process turns it on, as a variable of the
     * platform can: the cached value must decide first (the D44 kill switch).
     */
    public function defineCachedOff(): void
    {
        self::cachedConfiguration(['AGENTCAT_ENABLED' => 'false', 'AGENTCAT_PROJECT_ID' => LaravelTrackingFixture::PROJECT]);
        putenv('AGENTCAT_ENABLED=true');
    }

    public function defineCachedOn(): void
    {
        self::cachedConfiguration(['AGENTCAT_ENABLED' => 'true', 'AGENTCAT_PROJECT_ID' => LaravelTrackingFixture::PROJECT]);
    }

    /** `.env` does not set `AGENTCAT_ENABLED`; the process turns AgentCat on. */
    public function defineCachedUnset(): void
    {
        self::cachedConfiguration(['AGENTCAT_PROJECT_ID' => LaravelTrackingFixture::PROJECT]);
        putenv('AGENTCAT_ENABLED=true');
    }

    public function defineCachedProject(): void
    {
        self::cachedConfiguration(self::DOT_ENV);
    }

    public function defineCachedApiUrl(): void
    {
        self::cachedConfiguration([...self::DOT_ENV, 'AGENTCAT_API_URL' => 'https://cached.example.test']);
    }

    /** The name the TypeScript SDK's users know, which `api_url` falls back to. */
    public function defineCachedLegacyApiUrl(): void
    {
        self::cachedConfiguration([...self::DOT_ENV, 'MCPCAT_API_URL' => 'https://legacy.example.test']);
    }

    /** Diagnostics turned on by a variable of the platform: PHPUnit is a test environment, where they are off. */
    public function defineDiagnosticsOn(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        self::cachedConfiguration(self::DOT_ENV);
    }

    public function defineCachedDiagnosticsEndpoint(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        self::cachedConfiguration([...self::DOT_ENV, 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => $this->cachedCollector]);
    }

    public function defineCachedDiagnosticsToken(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        self::cachedConfiguration([...self::DOT_ENV, 'AGENTCAT_DIAGNOSTICS_TOKEN' => 'dgk_cached']);
    }

    /** Laravel's own cached `app.env`: the process holds no APP_ENV. */
    public function defineCachedAppEnvironment(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        self::cachedConfiguration(self::DOT_ENV);
        config(['app.env' => 'staging']);
    }

    /** `.env` holds the TypeScript name of the opt-out; the platform turns diagnostics on: off wins (D50). */
    public function defineCachedTypeScriptOptOut(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        self::cachedConfiguration([...self::DOT_ENV, 'DISABLE_DIAGNOSTICS' => 'true']);
    }

    /** `.env` holds the opt-out; the platform turns diagnostics on: the cached value decides first. */
    public function defineCachedOptOut(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        self::cachedConfiguration([...self::DOT_ENV, 'AGENTCAT_DISABLE_DIAGNOSTICS' => 'true']);
    }

    /** `.env` names the application channel for AgentCat's lines (D60: their own file otherwise). */
    public function defineCachedLogChannel(): void
    {
        self::cachedConfiguration([...self::DOT_ENV, 'AGENTCAT_LOG_CHANNEL' => 'agentcat-cached']);
        config(['logging.channels.agentcat-cached' => ['driver' => 'single', 'path' => $this->laravelDirectory . '/cached-channel.log']]);
    }

    /** `.env` holds a word that is not a D44 word; the process turns AgentCat on. */
    public function defineCachedUnknown(): void
    {
        self::cachedConfiguration(['AGENTCAT_ENABLED' => 'maybe', 'AGENTCAT_PROJECT_ID' => LaravelTrackingFixture::PROJECT]);
        putenv('AGENTCAT_ENABLED=true');
    }

    #[DefineEnvironment('defineANullProjectEntry')]
    public function testANullProjectEntryGoesToTheCachedProjectId(): void
    {
        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(LaravelTrackingFixture::PROJECT, $this->deliveredField('project_id'));
    }

    #[DefineEnvironment('defineCachedOff')]
    public function testACachedFalseStopsTheSharedClient(): void
    {
        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);
        Container::getInstance()->make(Client::class)->publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);
        $tools = (new LaravelHarness(ConformanceServer::class))->listTools()->tools;

        $this->assertSame([], $this->deliveries());
        $this->assertSame([], $this->logger->messages());
        $this->assertIsArray($tools);
        $this->assertSame('own', JsonAssert::at(end($tools), 'name')); // the server is not tracked either
    }

    #[DefineEnvironment('defineCachedOn')]
    public function testACachedTrueTurnsTheSharedClientOnInATestEnvironment(): void
    {
        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);

        $this->assertSame(self::SESSION, $this->deliveredField('session_id'));
        $this->assertContains(self::PUBLISHED, $this->logger->messages());
    }

    #[DefineEnvironment('defineCachedOff')]
    public function testAFakeStillRecordsWhenTheCacheSaysFalse(): void
    {
        $fake = AgentCat::fake();

        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);
        (new LaravelHarness(ConformanceServer::class))->callTool('echo', ['msg' => 'hi']);

        $fake->assertCustomEventPublished();
        $fake->assertTrackedCount(1);
        $this->assertSame([], $this->transport->deliveries);
    }

    #[DefineEnvironment('defineCachedUnset')]
    public function testWithoutACachedSwitchTheProcessDecides(): void
    {
        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);

        $this->assertSame(self::SESSION, $this->deliveredField('session_id'));
    }

    #[DefineEnvironment('defineCachedUnknown')]
    public function testAnUnknownCachedWordLeavesTheProcessToDecide(): void
    {
        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);

        $this->assertSame(self::SESSION, $this->deliveredField('session_id'));
    }

    /** I2 (c): an mcp/sdk Builder tracked with the shared client and no project ID gets the cached one. */
    #[DefineEnvironment('defineCachedProject')]
    public function testTheSharedClientTracksWithTheCachedProjectId(): void
    {
        AgentCat::track(TrackingFixture::builder());

        $started = array_values(array_filter($this->logger->messages(), static fn(string $line): bool => str_starts_with($line, 'AgentCat setup started')));
        $this->assertCount(1, $started);
        $this->assertStringStartsWith('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk mcp-sdk/builder', $started[0]);
        $this->assertNotContains('Warning: No projectId provided and no exporters configured. Events will not be sent anywhere.', $this->logger->messages());
    }

    #[DefineEnvironment('defineCachedApiUrl')]
    public function testTheSharedClientSendsToTheCachedApiUrl(): void
    {
        AgentCat::track(TrackingFixture::builder());
        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);

        $this->assertSame(['https://cached.example.test/v1/public/events/'], $this->urls());
    }

    #[DefineEnvironment('defineCachedLegacyApiUrl')]
    public function testTheSharedClientSendsToTheCachedLegacyApiUrl(): void
    {
        AgentCat::track(TrackingFixture::builder());
        AgentCat::publishCustomEvent(self::SESSION, LaravelTrackingFixture::PROJECT);

        $this->assertSame(['https://legacy.example.test/v1/public/events/'], $this->urls());
    }

    #[DefineEnvironment('defineCachedDiagnosticsEndpoint')]
    public function testDiagnosticsGoToTheCachedEndpoint(): void
    {
        $this->assertSame($this->cachedCollector . '/v1/logs', $this->diagnosticsRequest()->url);
    }

    #[DefineEnvironment('defineCachedDiagnosticsToken')]
    public function testDiagnosticsCarryTheCachedToken(): void
    {
        $this->assertSame('Bearer dgk_cached', $this->diagnosticsRequest()->headers['Authorization'] ?? null);
    }

    #[DefineEnvironment('defineCachedAppEnvironment')]
    public function testDiagnosticsCarryTheCachedAppEnvironment(): void
    {
        $attributes = JsonAssert::at(Json::decode($this->diagnosticsRequest()->body), 'resourceLogs', 0, 'resource', 'attributes');
        $this->assertIsList($attributes);
        $pairs = array_column(array_map(static fn(mixed $attribute): array => [JsonAssert::at($attribute, 'key'), JsonAssert::at($attribute, 'value', 'stringValue')], $attributes), 1, 0);

        $this->assertSame('staging', $pairs['deployment.environment'] ?? null);
    }

    #[DefineEnvironment('defineDiagnosticsOn')]
    public function testWithoutACachedOptOutTheDiagnosticsRun(): void
    {
        $request = $this->diagnosticsRequest();

        $this->assertSame(getenv('AGENTCAT_DIAGNOSTICS_ENDPOINT') . '/v1/logs', $request->url); // tests/bootstrap.php's closed port
    }

    #[DefineEnvironment('defineCachedTypeScriptOptOut')]
    public function testACachedTypeScriptOptOutKeepsTheTrackerOff(): void
    {
        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertFalse(Diagnostics::enabled());
    }

    #[DefineEnvironment('defineCachedTypeScriptOptOut')]
    public function testACachedTypeScriptOptOutKeepsTheSharedClientOff(): void
    {
        AgentCat::track(TrackingFixture::builder());

        $this->assertFalse(Diagnostics::enabled());
    }

    #[DefineEnvironment('defineCachedOptOut')]
    public function testACachedOptOutKeepsTheSharedClientOff(): void
    {
        AgentCat::track(TrackingFixture::builder());

        $this->assertFalse(Diagnostics::enabled());
    }

    #[DefineEnvironment('defineCachedLogChannel')]
    public function testTheLinesGoToTheCachedLogChannel(): void
    {
        Log::setLogger(null); // the tracker chooses the logger

        (new LaravelHarness(ConformanceServer::class))->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . LaravelTrackingFixture::PROJECT . ' | sdk laravel-mcp/server', (string) file_get_contents($this->laravelDirectory . '/cached-channel.log'));
        $this->assertSame('', $this->agentCatLog());
    }

    /**
     * The URLs of the events the API got, after a flush.
     *
     * @return list<string>
     */
    private function urls(): array
    {
        $this->assertTrue(EventQueue::global()->flush(1.0));

        return array_map(static fn(Delivery $delivery): string => $delivery->url, $this->transport->deliveries);
    }

    /** The one diagnostics request after the shared client tracked a Builder and the queue shut down. */
    private function diagnosticsRequest(): HttpRequest
    {
        $http = new RecordingHttpChannel();
        EventQueue::setGlobal(new EventQueue(transport: $this->transport, channel: $http));

        AgentCat::track(TrackingFixture::builder());
        EventQueue::global()->shutdown();

        $this->assertTrue(Diagnostics::enabled());
        $this->assertCount(1, $http->requests);

        return $http->requests[0];
    }
}
