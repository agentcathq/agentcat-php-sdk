<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

use AgentCat\Internal\Diagnostics\DiagnosticsSwitch;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;

/**
 * A framework bridge's `agentcat` configuration (snake_case, the framework's style) as `Options` (D42: the bridges map
 * their own config style onto `Options::fromArray()`). A hook is the name of something the bridge resolves when the
 * hook runs — never on the request path (D04) — so the configuration holds no closure and can be cached.
 *
 * @internal
 */
final class ConfigOptions
{
    /** @var array<string, string> config key => `Options` argument name */
    public const array KEYS = [
        'enable_report_missing' => 'enableReportMissing',
        'enable_tracing' => 'enableTracing',
        'enable_tool_call_context' => 'enableToolCallContext',
        'custom_context_description' => 'customContextDescription',
        'enable_agent_tracking' => 'enableAgentTracking',
        'resolve_session_id' => 'resolveSessionId',
        'identify' => 'identify',
        'redact_sensitive_information' => 'redactSensitiveInformation',
        'redact_event' => 'redactEvent',
        'api_url' => 'apiBaseUrl',
        'event_tags' => 'eventTags',
        'event_properties' => 'eventProperties',
        'exporters' => 'exporters',
        'disable_diagnostics' => 'disableDiagnostics',
        'disable_signal_handlers' => 'disableSignalHandlers',
    ];

    private const array HOOKS = ['resolveSessionId', 'identify', 'redactSensitiveInformation', 'redactEvent', 'eventTags', 'eventProperties'];

    private const string BAD_DIAGNOSTICS_VALUE = 'WARN: AgentCat config "agentcat.disable_diagnostics" is "%s", not one of true, 1, yes, on, false, 0, no, off; the value is treated as true';

    /**
     * Never throws: an unknown key or a bad value logs one line and the default is used (`Options::fromArray()`).
     *
     * @param array<array-key, mixed> $config     the bridge's `agentcat` configuration
     * @param list<string>            $bridgeKeys keys the bridge reads itself (not options)
     * @param \Closure(string): mixed $resolve    a hook's name → the callable, when the hook runs
     */
    public static function options(array $config, array $bridgeKeys, \Closure $resolve): Options
    {
        $mapped = [];
        foreach ($config as $key => $value) {
            if (in_array($key, $bridgeKeys, true)) {
                continue;
            }
            $option = self::KEYS[$key] ?? null;
            if ($option === null) {
                Log::write(sprintf('WARN: Unknown AgentCat option "agentcat.%s" ignored', $key));

                continue;
            }
            if ($value === null) {
                continue; // env() of an unset variable: the default
            }
            if ($option === 'disableDiagnostics') {
                $mapped[$option] = self::diagnosticsOff($value, log: true);

                continue;
            }
            $mapped[$option] = in_array($option, self::HOOKS, true) && is_string($value) && !is_callable($value)
                ? self::named($value, $resolve)
                : $value;
        }

        return Options::fromArray($mapped);
    }

    /**
     * The `disable_diagnostics` value of a bridge's configuration: one value, or a list of them (config/agentcat.php
     * lists `AGENTCAT_DISABLE_DIAGNOSTICS` and the TypeScript name `DISABLE_DIAGNOSTICS`), each in the words of
     * `DiagnosticsSwitch::option()`. True when one of them turns diagnostics off: off wins (D50). Null is unset. Any
     * other value turns them off too, as an opt-out fails closed, and with `$log` it logs one line.
     */
    public static function diagnosticsOff(mixed $value, bool $log = false): bool
    {
        $off = false;
        foreach (is_array($value) ? $value : [$value] as $entry) {
            if ($entry === null) {
                continue;
            }
            $disabled = DiagnosticsSwitch::option($entry);
            if ($disabled === null && $log) {
                Log::write(sprintf(self::BAD_DIAGNOSTICS_VALUE, is_scalar($entry) ? (string) $entry : get_debug_type($entry)));
            }
            $off = $off || ($disabled ?? true);
        }

        return $off;
    }

    /**
     * A hook that resolves its name when it runs. What the name resolves to must be callable; the hook guard logs
     * anything it throws (D32).
     *
     * @param \Closure(string): mixed $resolve
     *
     * @return \Closure(mixed ...): mixed
     */
    private static function named(string $name, \Closure $resolve): \Closure
    {
        return static function (mixed ...$arguments) use ($name, $resolve): mixed {
            $hook = $resolve($name);
            if (!is_callable($hook)) {
                throw new \UnexpectedValueException(sprintf('%s is not invokable', $name));
            }

            return $hook(...$arguments);
        };
    }
}
