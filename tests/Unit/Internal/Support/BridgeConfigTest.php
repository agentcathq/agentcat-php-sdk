<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Tests\Support\Environment;
use PHPUnit\Framework\TestCase;

/** The values of a bridge's configuration that every client reads before the environment (plan E1 header conflict 12). */
final class BridgeConfigTest extends TestCase
{
    private Environment $environment;

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'AGENTCAT_DIAGNOSTICS_ENDPOINT', 'AGENTCAT_DIAGNOSTICS_TOKEN', 'APP_ENV', 'AGENTCAT_ENABLED');
    }

    #[\Override]
    protected function tearDown(): void
    {
        BridgeConfig::reset();
        TestMode::reset();
        $this->environment->restore();
    }

    public function testWithoutAConfigurationTheEnvironmentDecides(): void
    {
        putenv('AGENTCAT_PROJECT_ID=proj_env');

        $this->assertSame('proj_env', BridgeConfig::env('AGENTCAT_PROJECT_ID'));
        $this->assertNull(BridgeConfig::env('AGENTCAT_API_URL'));
        $this->assertFalse(BridgeConfig::diagnosticsOff());
    }

    public function testAConfiguredValueComesBeforeTheEnvironment(): void
    {
        putenv('AGENTCAT_PROJECT_ID=proj_env');

        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => 'proj_configured', 'AGENTCAT_API_URL' => 'https://configured.test'], true);

        $this->assertSame('proj_configured', BridgeConfig::env('AGENTCAT_PROJECT_ID'));
        $this->assertSame('https://configured.test', BridgeConfig::env('AGENTCAT_API_URL'));
        $this->assertTrue(BridgeConfig::diagnosticsOff());
    }

    /** `env()` of an unset variable gives null, or '' for an empty one: the environment still decides. */
    public function testANullOrEmptyValueLeavesTheNameToTheEnvironment(): void
    {
        putenv('AGENTCAT_PROJECT_ID=proj_env');
        putenv('AGENTCAT_API_URL=https://env.test');

        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => null, 'AGENTCAT_API_URL' => ''], false);

        $this->assertSame('proj_env', BridgeConfig::env('AGENTCAT_PROJECT_ID'));
        $this->assertSame('https://env.test', BridgeConfig::env('AGENTCAT_API_URL'));
    }

    public function testTheLastCallWins(): void
    {
        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => 'proj_first', 'AGENTCAT_API_URL' => 'https://first.test'], true);

        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => 'proj_second'], false);

        $this->assertSame('proj_second', BridgeConfig::env('AGENTCAT_PROJECT_ID'));
        $this->assertNull(BridgeConfig::env('AGENTCAT_API_URL'));
        $this->assertFalse(BridgeConfig::diagnosticsOff());
    }

    public function testResetForgetsTheConfiguration(): void
    {
        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => 'proj_configured'], true);

        BridgeConfig::reset();

        $this->assertNull(BridgeConfig::env('AGENTCAT_PROJECT_ID'));
        $this->assertFalse(BridgeConfig::diagnosticsOff());
    }

    /** Both bridges boot with this: their `agentcat` configuration, and the application's environment for diagnostics. */
    public function testABridgeConfigurationGivesEveryNameAndTheSwitch(): void
    {
        BridgeConfig::fromBridge([
            'enabled' => 'off',
            'project_id' => 'proj_configured',
            'api_url' => 'https://api.configured.test',
            'diagnostics_endpoint' => 'https://otel.configured.test',
            'diagnostics_token' => 'dgk_configured',
            'disable_diagnostics' => [null, 'yes'],
        ], 'staging');

        $this->assertSame(['proj_configured', 'https://api.configured.test', 'https://otel.configured.test', 'dgk_configured', 'staging'], array_map(BridgeConfig::env(...), ['AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'AGENTCAT_DIAGNOSTICS_ENDPOINT', 'AGENTCAT_DIAGNOSTICS_TOKEN', 'APP_ENV']));
        $this->assertTrue(BridgeConfig::diagnosticsOff());
        putenv('AGENTCAT_ENABLED=true');
        $this->assertFalse(TestMode::sdkEnabled()); // the configured switch decides before AGENTCAT_ENABLED
    }

    /** A value that is no string, an unset one and an unknown word leave each name, and the switch, to the environment. */
    public function testABridgeConfigurationWithoutValuesLeavesTheEnvironmentToDecide(): void
    {
        putenv('AGENTCAT_PROJECT_ID=proj_env');
        putenv('AGENTCAT_ENABLED=true');
        TestMode::configure(false);

        BridgeConfig::fromBridge(['enabled' => 'maybe', 'project_id' => 12345, 'api_url' => null, 'disable_diagnostics' => [null, null]], null);

        $this->assertSame('proj_env', BridgeConfig::env('AGENTCAT_PROJECT_ID'));
        $this->assertNull(BridgeConfig::env('AGENTCAT_API_URL'));
        $this->assertNull(BridgeConfig::env('APP_ENV'));
        $this->assertFalse(BridgeConfig::diagnosticsOff());
        $this->assertTrue(TestMode::sdkEnabled());
    }
}
