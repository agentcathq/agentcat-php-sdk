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
 * The values come from `env()` as often as not, so the bool keys take the D44 words, as `enabled` does (`env()` gives
 * `AGENTCAT_ENABLE_TRACING=0` as the string "0"). A bad value logs one line that names the key as the customer wrote
 * it, `agentcat.<key>`, and the default is used.
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

    private const array BOOLS = ['enableReportMissing', 'enableTracing', 'enableToolCallContext', 'enableAgentTracking', 'disableSignalHandlers'];

    private const array STRINGS = ['customContextDescription', 'apiBaseUrl'];

    private const string BAD_WORD = 'WARN: AgentCat config "agentcat.%s" is "%s", not one of true, 1, yes, on, false, 0, no, off; the default is used';

    private const string WRONG_TYPE = 'WARN: AgentCat option "agentcat.%s" must be %s, got %s; the default is used';

    private const string BAD_DIAGNOSTICS_VALUE = 'WARN: AgentCat config "agentcat.disable_diagnostics" is "%s", not one of true, 1, yes, on, false, 0, no, off; the value is treated as true';

    /**
     * Never throws: an unknown key or a bad value logs one line and the default is used.
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
            $value = $value === null ? null : self::value((string) $key, $option, $value, $resolve);
            if ($value !== null) {
                $mapped[$option] = $value; // null: env() of an unset variable, or a bad value; the default
            }
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
                Log::write(sprintf(self::BAD_DIAGNOSTICS_VALUE, self::shown($entry)));
            }
            $off = $off || ($disabled ?? true);
        }

        return $off;
    }

    /**
     * The value of a key for `Options::fromArray()`, of the type it takes; null for a bad value (one line) or a blank
     * bool word (unset).
     *
     * @param \Closure(string): mixed $resolve
     */
    private static function value(string $key, string $option, mixed $value, \Closure $resolve): mixed
    {
        if ($option === 'disableDiagnostics') {
            return self::diagnosticsOff($value, log: true);
        }
        if (in_array($option, self::BOOLS, true)) {
            $word = TestMode::word($value);
            if ($word === null && (!is_string($value) || trim($value) !== '')) {
                Log::write(sprintf(self::BAD_WORD, $key, self::shown($value)));
            }

            return $word;
        }
        if (in_array($option, self::STRINGS, true)) {
            return is_string($value) ? $value : self::reject($key, 'string or null', $value);
        }
        if (in_array($option, self::HOOKS, true)) {
            if (is_string($value) && !is_callable($value)) {
                return self::named($value, $resolve);
            }

            return is_callable($value) ? $value : self::reject($key, 'callable or null', $value);
        }

        return is_array($value) ? $value : self::reject($key, 'array or null', $value); // exporters
    }

    private static function reject(string $key, string $expected, mixed $value): null
    {
        Log::write(sprintf(self::WRONG_TYPE, $key, $expected, get_debug_type($value)));

        return null;
    }

    /** A value as a bad-value line shows it: a scalar as itself, anything else by its type. */
    private static function shown(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
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
