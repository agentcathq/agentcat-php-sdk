<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\AgentCat;
use AgentCat\Client;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\MemoryLogger;
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

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        Log::setLogger($this->logger = new MemoryLogger()); // nothing goes to agentcat.log in the test HOME
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
}
