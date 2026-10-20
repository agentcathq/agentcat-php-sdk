<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\AgentCat;
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
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ruling P7: the shared client, which an application's own `AgentCat::track()` or `AgentCat::publishCustomEvent()`
 * uses, follows the `agentcat` configuration before the process environment (plan E1 header conflict 12): a value can
 * come from a literal in config/packages/agentcat.yaml, a parameter or a secret, which the environment does not hold.
 * `AgentCatBundle::boot()` hands each value to the core. Each test boots a kernel: 0.2 to 0.3 s measured on PHP 8.3
 * and 8.5.
 */
final class BundleConfigurationTest extends SymfonyTestCase
{
    private const string PROJECT = 'proj_ccccccccccccccccccccccccccc';

    private const string SESSION = 'ses_ccccccccccccccccccccccccccc';

    private const string PUBLISHED = "Published custom event for session ses_ccccccccccccccccccccccccccc with type 'agentcat:custom'";

    private const array ON = ['enabled' => true, 'project_id' => self::PROJECT];

    private MemoryLogger $logger;

    /** The diagnostics variables of the process; a test turns diagnostics on as a platform variable can. */
    private ?Environment $diagnosticsEnvironment = null;

    /** The collector the configuration names for diagnostics: a closed local port (ruling P8 of Plan E1). */
    private string $configuredCollector = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->diagnosticsEnvironment = Environment::clear('AGENTCAT_DISABLE_DIAGNOSTICS', 'DISABLE_DIAGNOSTICS', 'AGENTCAT_DIAGNOSTICS_TOKEN', 'MCPCAT_API_URL', 'APP_ENV');
        EventApiGuard::set(); // tests/bootstrap.php's closed event API again, after the clear
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=true'); // tests/bootstrap.php's value: a test that wants diagnostics says so
        $this->configuredCollector = MockCollector::closedPortUrl() . '/configured';
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

    /** D44: the configured switch stops the shared client, also where the process turns AgentCat on. */
    #[MaximumDuration(1000)]
    public function testAConfiguredFalseStopsTheSharedClient(): void
    {
        putenv('AGENTCAT_ENABLED=true');
        $this->kernel(agentcat: ['enabled' => false, 'project_id' => self::PROJECT]);

        AgentCat::publishCustomEvent(self::SESSION, self::PROJECT);

        $this->assertSame([], $this->deliveries());
        $this->assertSame([], $this->logger->messages());
    }

    #[MaximumDuration(1000)]
    public function testAConfiguredTrueTurnsTheSharedClientOnInATestEnvironment(): void
    {
        $this->kernel(agentcat: self::ON);

        AgentCat::publishCustomEvent(self::SESSION, self::PROJECT);

        $this->assertSame([self::SESSION], $this->fields('session_id'));
        $this->assertContains(self::PUBLISHED, $this->logger->messages());
    }

    /** `enabled` from `%env(default::AGENTCAT_ENABLED)%` with the variable unset: the process decides. */
    #[MaximumDuration(1000)]
    public function testWithoutAConfiguredSwitchTheProcessDecides(): void
    {
        $this->kernel(agentcat: ['project_id' => self::PROJECT]);
        putenv('AGENTCAT_ENABLED=true');

        AgentCat::publishCustomEvent(self::SESSION, self::PROJECT);

        $this->assertSame([self::SESSION], $this->fields('session_id'));
    }

    #[MaximumDuration(1000)]
    public function testAnUnknownConfiguredWordLeavesTheProcessToDecide(): void
    {
        $this->kernel(agentcat: ['enabled' => 'maybe', 'project_id' => self::PROJECT]);
        putenv('AGENTCAT_ENABLED=true');

        AgentCat::publishCustomEvent(self::SESSION, self::PROJECT);

        $this->assertSame([self::SESSION], $this->fields('session_id'));
    }

    /** An mcp/sdk Builder of the application, tracked with the shared client and no project ID, gets the configured one. */
    #[MaximumDuration(1000)]
    public function testTheSharedClientTracksWithTheConfiguredProjectId(): void
    {
        $this->kernel(agentcat: self::ON);

        AgentCat::track(TrackingFixture::builder());

        $started = array_values(array_filter($this->logger->messages(), static fn(string $line): bool => str_starts_with($line, 'AgentCat setup started')));
        $this->assertCount(1, $started);
        $this->assertStringStartsWith('AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder', $started[0]);
    }

    #[MaximumDuration(1000)]
    public function testTheSharedClientSendsToTheConfiguredApiUrl(): void
    {
        $this->kernel(agentcat: [...self::ON, 'api_url' => 'https://configured.example.test']);

        AgentCat::track(TrackingFixture::builder());
        AgentCat::publishCustomEvent(self::SESSION, self::PROJECT);

        $this->assertSame(['https://configured.example.test/v1/public/events/'], array_map(static fn(Delivery $delivery): string => $delivery->url, $this->deliveries()));
    }

    #[MaximumDuration(1000)]
    public function testDiagnosticsGoToTheConfiguredEndpoint(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->kernel(agentcat: [...self::ON, 'diagnostics_endpoint' => $this->configuredCollector]);

        $this->assertSame($this->configuredCollector . '/v1/logs', $this->diagnosticsRequest()->url);
    }

    #[MaximumDuration(1000)]
    public function testDiagnosticsCarryTheConfiguredToken(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->kernel(agentcat: [...self::ON, 'diagnostics_token' => 'dgk_configured']);

        $this->assertSame('Bearer dgk_configured', $this->diagnosticsRequest()->headers['Authorization'] ?? null);
    }

    /** The kernel's environment is the deployment environment of diagnostics (Laravel: `app.env`). */
    #[MaximumDuration(1000)]
    public function testDiagnosticsCarryTheKernelEnvironment(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->kernel(agentcat: self::ON);

        $attributes = JsonAssert::at(Json::decode($this->diagnosticsRequest()->body), 'resourceLogs', 0, 'resource', 'attributes');
        $this->assertIsList($attributes);
        $pairs = array_column(array_map(static fn(mixed $attribute): array => [JsonAssert::at($attribute, 'key'), JsonAssert::at($attribute, 'value', 'stringValue')], $attributes), 1, 0);

        $this->assertSame('test', $pairs['deployment.environment'] ?? null);
    }

    #[MaximumDuration(1000)]
    public function testWithoutAConfiguredOptOutTheDiagnosticsRun(): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->kernel(agentcat: self::ON);

        $this->assertSame(getenv('AGENTCAT_DIAGNOSTICS_ENDPOINT') . '/v1/logs', $this->diagnosticsRequest()->url); // tests/bootstrap.php's closed port
    }

    /** @return iterable<string, array{mixed}> */
    public static function optOuts(): iterable
    {
        yield 'a value' => [true];
        yield 'a word' => ['yes'];
        yield 'one value of a list' => [[null, 'on']];
    }

    /** D50: off wins; the configuration turns diagnostics off where the process turns them on. */
    #[DataProvider('optOuts')]
    #[MaximumDuration(1000)]
    public function testAConfiguredOptOutKeepsTheSharedClientOff(mixed $optOut): void
    {
        putenv('AGENTCAT_DISABLE_DIAGNOSTICS=false');
        $this->kernel(agentcat: [...self::ON, 'disable_diagnostics' => $optOut]);

        AgentCat::track(TrackingFixture::builder());

        $this->assertFalse(Diagnostics::enabled());
    }

    /**
     * The value of a field of each event the API got, after a flush.
     *
     * @return list<mixed>
     */
    private function fields(string $field): array
    {
        return array_map(static fn(Delivery $delivery): mixed => JsonAssert::at(Json::decode($delivery->body), $field), $this->deliveries());
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
