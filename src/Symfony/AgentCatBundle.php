<?php

declare(strict_types=1);

namespace AgentCat\Symfony;

use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Symfony\Internal\AnswerLength;
use AgentCat\Symfony\Internal\DependencyInjection\TrackBuildersPass;
use AgentCat\Symfony\Internal\ServerTracker;
use AgentCat\Symfony\Internal\TerminateListener;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tracks every symfony/mcp-bundle server of the application with AgentCat. The Flex recipe registers it and writes
 * config/packages/agentcat.yaml: set `AGENTCAT_PROJECT_ID`. `php bin/console config:dump-reference agentcat` shows
 * every key of the `agentcat` configuration.
 */
final class AgentCatBundle extends AbstractBundle
{
    private const array HOOKS = ['identify', 'resolve_session_id', 'event_tags', 'event_properties', 'redact_sensitive_information', 'redact_event'];

    private const array BOOLS = ['enable_report_missing' => true, 'enable_tracing' => true, 'enable_tool_call_context' => true];

    /** The configuration's root key is `agentcat` (D55), not the `agent_cat` that Symfony derives from the class name. */
    public function __construct()
    {
        $this->extensionAlias = 'agentcat'; // set here: #[\Override] on a redeclared property needs PHP 8.5
    }

    #[\Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $children->scalarNode('enabled')->defaultValue('%env(default::AGENTCAT_ENABLED)%')->info('On or off for every MCP server and every AgentCat call: true or false. Unset: on, except while your tests run.')->end();
        $children->scalarNode('project_id')->defaultValue('%env(default::AGENTCAT_PROJECT_ID)%')->info('Your AgentCat project ID (proj_…). Unset: no events go to AgentCat; exporters still run.')->end();
        $children->variableNode('exclude_servers')->defaultValue([])->info('Servers AgentCat never tracks: names of mcp.servers entries; a string is one name. Names written here are read when the container compiles, names from environment variables when each server is built.')->end();
        $children->arrayNode('projects')->info('Another project for some servers: mcp.servers name => project ID. A null project is project_id.')->useAttributeAsKey('server')->scalarPrototype()->end()->defaultValue([])->end();
        $children->scalarNode('logger')->defaultNull()->info('The service ID of a PSR-3 logger for AgentCat\'s own lines, for example monolog.logger.mcp, written here, not in an environment variable. Unset: their own file, agentcat.log in the log directory.')->end();
        $children->scalarNode('api_url')->defaultValue('%env(default::AGENTCAT_API_URL)%')->info('The AgentCat API. Unset: AgentCat\'s. MCPCAT_API_URL, the TypeScript SDK\'s name, is read too.')->end();
        foreach (self::BOOLS as $key => $default) {
            $children->scalarNode($key)->defaultValue($default)->end();
        }
        $children->scalarNode('custom_context_description')->defaultNull()->end();
        $children->scalarNode('enable_agent_tracking')->defaultFalse()->end();
        foreach (self::HOOKS as $hook) {
            $children->scalarNode($hook)->defaultNull()->info('The service ID of an invokable service, written here, not in an environment variable. The service is resolved when the hook runs.')->end();
        }
        $children->variableNode('exporters')->defaultNull()->info('Your own telemetry tools: name => {type: otlp|datadog|sentry|posthog, …}. A ~ entry is skipped.')->end();
        $children->variableNode('disable_diagnostics')->defaultValue(['%env(default::AGENTCAT_DISABLE_DIAGNOSTICS)%', '%env(default::DISABLE_DIAGNOSTICS)%'])->info('Diagnostics: AgentCat\'s own log lines, sent to AgentCat so that it can find broken installs. true turns them off; in a list, one true value is enough. DISABLE_DIAGNOSTICS, the TypeScript SDK\'s name, is read too.')->end();
        $children->scalarNode('diagnostics_endpoint')->defaultValue('%env(default::AGENTCAT_DIAGNOSTICS_ENDPOINT)%')->info('Where diagnostics go: an OpenTelemetry collector (OTLP/HTTP logs). Unset: AgentCat\'s.')->end();
        $children->scalarNode('diagnostics_token')->defaultValue('%env(default::AGENTCAT_DIAGNOSTICS_TOKEN)%')->info('The bearer token of diagnostics_endpoint. Unset: AgentCat\'s.')->end();
        $children->scalarNode('disable_signal_handlers')->defaultFalse()->end();
    }

    /** @param array<array-key, mixed> $config */
    #[\Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The names written in the configuration: the pass removes those servers when the container compiles. The
        // tracker reads the configuration's names again when it builds a server, from environment variables too.
        $excluded = $config['exclude_servers'] ?? [];
        $names = [];
        foreach (is_array($excluded) ? $excluded : [$excluded] as $name) {
            if (is_string($name) && !$this->variable($builder, $name)) {
                $names[] = $name;
            }
        }
        $builder->setParameter('agentcat.exclude_servers', $names);
        // The services the configuration names by ID: the hooks and the logger. The container knows a service by the ID
        // written here; an ID from an environment variable is known only at runtime, when the tracker says so.
        $services = [];
        $variables = [];
        foreach ([...self::HOOKS, 'logger'] as $key) {
            $id = $config[$key] ?? null;
            if (is_string($id) && $this->variable($builder, $id)) {
                $variables[] = $key;
            } elseif (is_string($id) && $id !== '') {
                // A missing service is left out of the locator, and the container compiles: the hook guard logs it when
                // the hook runs, the tracker when it chooses the logger (D32).
                $services[$id] = new Reference($id, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            }
        }
        // The configuration as a service: its environment variables resolve when AgentCat reads it, never at compile.
        $builder->register('agentcat.config', \ArrayObject::class)->setArguments([$config])->setPublic(true);
        $builder->register('agentcat.hooks', ServiceLocator::class)->setArguments([$services])->addTag('container.service_locator'); // and the logger
        $builder->register('agentcat.server_tracker', ServerTracker::class)->setArguments([
            new ServiceClosureArgument(new Reference('agentcat.config')),
            new Reference('agentcat.hooks'),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '%kernel.logs_dir%/agentcat.log',
            '%agentcat.servers%', // every server name, which TrackBuildersPass writes
            $variables,
        ]);
        // D58: the wait for delivery after each response, last of kernel.terminate (header conflict 7).
        $builder->register('agentcat.terminate_listener', TerminateListener::class)
            ->addTag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'priority' => TerminateListener::PRIORITY]);
        // D62: boot() makes it a kernel.response listener where responses end with the script.
        $builder->register(AnswerLength::SERVICE, AnswerLength::class)->setArguments([new Reference('agentcat.server_tracker')])->setPublic(true);
    }

    /** Whether a value of the configuration holds an environment variable, which resolves at runtime only. */
    private function variable(ContainerBuilder $builder, string $value): bool
    {
        $variables = [];
        $builder->resolveEnvPlaceholders($value, null, $variables);

        return $variables !== [];
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TrackBuildersPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
    }

    /**
     * `agentcat.enabled` and every other name AgentCat reads decide for every client, the shared one too, before the
     * environment (plan E1 header conflict 12): a literal, a parameter or a secret of the configuration is not in the
     * environment. Diagnostics get the kernel's environment. A fake still records. The tracker logs the bad values;
     * nothing is logged here, and boot tracks nothing. Where this process's SAPI sends a response only when the script
     * ends, the answers of tracked servers get their length (D62).
     */
    #[\Override]
    public function boot(): void
    {
        if (!$this->container instanceof ContainerInterface) {
            return; // Symfony gives a bundle its container before it boots it
        }
        $config = [];
        try {
            $service = $this->container->get('agentcat.config');
            $config = $service instanceof \ArrayObject ? $service->getArrayCopy() : [];
        } catch (\Throwable) {
            // A variable without a default is not set: the tracker logs it when it tracks a server (D32).
        }
        BridgeConfig::fromBridge($config, $this->container->getParameter('kernel.environment'));
        AnswerLength::register($this->container);
    }
}
