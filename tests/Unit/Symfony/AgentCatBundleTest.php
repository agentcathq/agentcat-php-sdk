<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Symfony;

use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Symfony\AgentCatBundle;
use AgentCat\Symfony\Internal\DependencyInjection\TrackBuildersPass;
use AgentCat\Symfony\Internal\ServerTracker;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * The `agentcat` configuration tree (D55, ruling P7): the keys of config/agentcat.php in Symfony's words, with
 * `%env(default::AGENTCAT_*)%` defaults that resolve to null when a variable is unset.
 */
final class AgentCatBundleTest extends TestCase
{
    public function testTheDefaults(): void
    {
        $this->assertSame([
            'enabled' => '%env(default::AGENTCAT_ENABLED)%',
            'project_id' => '%env(default::AGENTCAT_PROJECT_ID)%',
            'exclude_servers' => [],
            'projects' => [],
            'logger' => null,
            'api_url' => '%env(default::AGENTCAT_API_URL)%',
            'enable_report_missing' => true,
            'enable_tracing' => true,
            'enable_tool_call_context' => true,
            'custom_context_description' => null,
            'enable_agent_tracking' => false,
            'identify' => null,
            'resolve_session_id' => null,
            'event_tags' => null,
            'event_properties' => null,
            'redact_sensitive_information' => null,
            'redact_event' => null,
            'exporters' => null,
            'disable_diagnostics' => ['%env(default::AGENTCAT_DISABLE_DIAGNOSTICS)%', '%env(default::DISABLE_DIAGNOSTICS)%'],
            'diagnostics_endpoint' => '%env(default::AGENTCAT_DIAGNOSTICS_ENDPOINT)%',
            'diagnostics_token' => '%env(default::AGENTCAT_DIAGNOSTICS_TOKEN)%',
            'disable_signal_handlers' => false,
        ], $this->process([]));
    }

    /** Every key is a key of the bundle or an option, as in config/agentcat.php (Laravel's log_channel is logger here). */
    public function testTheTreeHasTheBundleKeysAndTheOptions(): void
    {
        $this->assertEqualsCanonicalizing([...ServerTracker::BRIDGE_KEYS, ...array_keys(ConfigOptions::KEYS)], array_keys($this->process([])));
    }

    /**
     * `exclude_servers` takes any value: a name or a list of names written here, which the pass reads when the
     * container compiles, or an environment variable of any type, which the tracker reads when it builds a server.
     */
    public function testExclusionsTakeNamesListsAndVariables(): void
    {
        $this->assertSame(['internal', 'admin', '%env(csv:AGENTCAT_EXCLUDE)%'], array_map(fn(mixed $exclusion): mixed => $this->process(['exclude_servers' => $exclusion])['exclude_servers'] ?? null, ['internal', 'admin', '%env(csv:AGENTCAT_EXCLUDE)%']));
        $this->assertSame(['internal', 'admin'], $this->process(['exclude_servers' => ['internal', 'admin']])['exclude_servers'] ?? null);
    }

    /** The keys of `projects` are written as symfony/mcp-bundle writes the keys of `mcp.servers`. */
    public function testProjectsAreServerNamesAndProjectIds(): void
    {
        $projects = $this->process(['projects' => ['main' => 'proj_m', 'my-server' => '%env(default::AGENTCAT_MY_SERVER_PROJECT_ID)%', 'other' => null]])['projects'] ?? null;

        $this->assertSame(['main' => 'proj_m', 'my_server' => '%env(default::AGENTCAT_MY_SERVER_PROJECT_ID)%', 'other' => null], $projects);
    }

    /** The bool keys keep what they are given, for the D44 words at runtime: `%env(AGENTCAT_ENABLE_TRACING)%` compiles. */
    public function testTheBoolKeysTakeWordsAndVariables(): void
    {
        $config = $this->process(['enable_tracing' => '%env(AGENTCAT_ENABLE_TRACING)%', 'enable_report_missing' => 'no', 'enable_agent_tracking' => 1, 'disable_signal_handlers' => 'maybe', 'enable_tool_call_context' => false]);

        $this->assertSame(['%env(AGENTCAT_ENABLE_TRACING)%', 'no', 1, 'maybe', false], [$config['enable_tracing'] ?? null, $config['enable_report_missing'] ?? null, $config['enable_agent_tracking'] ?? null, $config['disable_signal_handlers'] ?? null, $config['enable_tool_call_context'] ?? null]);
    }

    public function testExportersAndTheOptOutTakeAnyValue(): void
    {
        $config = $this->process(['exporters' => ['otlp' => null, 'datadog' => ['type' => 'datadog']], 'disable_diagnostics' => true]);

        $this->assertSame(['otlp' => null, 'datadog' => ['type' => 'datadog']], $config['exporters'] ?? null);
        $this->assertTrue($config['disable_diagnostics'] ?? null);
    }

    /** Symfony validates the tree when the container compiles: an unknown key fails there. */
    public function testAnUnknownKeyFailsTheCompile(): void
    {
        $thrown = Thrown::by(InvalidConfigurationException::class, fn(): array => $this->process(['enable_tracking' => true]));

        $this->assertStringContainsString('Unrecognized option "enable_tracking" under "agentcat"', $thrown->getMessage());
    }

    /**
     * At -100 before optimization: after symfony/mcp-bundle's `McpAppPass` (10) and `McpPass` (0), before a pass that
     * the application adds at the same priority (the kernel's own `build()` runs after the bundles').
     */
    public function testThePassRunsBeforeOptimizationAtPriorityMinus100(): void
    {
        $container = new ContainerBuilder();
        $before = $this->pass();
        $after = $this->pass();
        $container->addCompilerPass($before, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);

        (new AgentCatBundle())->build($container);
        $container->addCompilerPass($after, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);

        $passes = array_values(array_filter($container->getCompilerPassConfig()->getBeforeOptimizationPasses(), static fn(CompilerPassInterface $pass): bool => in_array($pass, [$before, $after], true) || $pass instanceof TrackBuildersPass));
        $this->assertSame([$before, TrackBuildersPass::class, $after], array_map(static fn(CompilerPassInterface $pass): CompilerPassInterface|string => $pass instanceof TrackBuildersPass ? TrackBuildersPass::class : $pass, $passes));
    }

    /** Symfony gives a bundle its container before it boots it; without one, boot changes nothing. */
    public function testBootWithoutAContainerChangesNothing(): void
    {
        BridgeConfig::configure(['AGENTCAT_PROJECT_ID' => 'proj_x'], true);
        TestMode::configure(false);
        $bundle = new AgentCatBundle();
        $bundle->setContainer(null);
        try {
            $bundle->boot();

            $this->assertSame('proj_x', BridgeConfig::env('AGENTCAT_PROJECT_ID'));
            $this->assertTrue(BridgeConfig::diagnosticsOff());
            $this->assertFalse(TestMode::sdkEnabled());
        } finally {
            BridgeConfig::reset();
            TestMode::reset();
        }
    }

    private function pass(): CompilerPassInterface
    {
        return new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void {}
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $extension = (new AgentCatBundle())->getContainerExtension();
        $this->assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $this->assertSame('agentcat', $extension->getAlias());
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        $this->assertInstanceOf(ConfigurationInterface::class, $configuration);

        /** @var array<string, mixed> */
        return (new Processor())->processConfiguration($configuration, [$config]);
    }
}
