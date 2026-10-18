<?php

declare(strict_types=1);

namespace AgentCat;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\ExporterConfigs;

/**
 * Options for tracking an MCP server. Use named arguments: the argument names are public API.
 *
 * Hooks never run on the request path. AgentCat runs them later, when it delivers the event.
 */
final readonly class Options
{
    private const array KEYS = [
        'enableReportMissing', 'enableTracing', 'enableToolCallContext', 'customContextDescription', 'enableAgentTracking',
        'resolveSessionId', 'identify', 'redactSensitiveInformation', 'redactEvent', 'apiBaseUrl', 'eventTags', 'eventProperties',
        'exporters', 'disableDiagnostics', 'disableSignalHandlers',
    ];

    /**
     * @param bool $enableReportMissing Add the `get_more_tools` tool, so agents can report tools they miss.
     * @param bool $enableTracing Publish one event for every tool call.
     * @param bool $enableToolCallContext Add the `context` parameter to your tools, so agents explain each call.
     * @param string|null $customContextDescription Replaces the default description of the `context` parameter.
     * @param bool $enableAgentTracking Add the `agent_id` parameter to your tools, to tell agents apart.
     * @param \Closure(ToolCall): (string|null)|null $resolveSessionId Returns the session ID for a call, or null to let AgentCat choose.
     * @param \Closure(ToolCall): (Identity|null)|null $identify Returns who made a call, or null for an anonymous call.
     * @param (\Closure(string): string)|null $redactSensitiveInformation Rewrites every string of an event before it is sent.
     * @param \Closure(Event): (Event|null)|null $redactEvent Changes an event before it is sent, or returns null to drop it.
     * @param string|null $apiBaseUrl Base URL of the AgentCat API; null uses the default.
     * @param \Closure(ToolCall): (array<array-key, mixed>|null)|null $eventTags Returns extra tags (string keys and values) for a call.
     * @param (\Closure(ToolCall): mixed)|null $eventProperties Returns extra JSON-encodable properties for a call.
     * @param array<array-key, OtlpExporter|DatadogExporter|SentryExporter|PostHogExporter>|null $exporters Also sends every
     *        event to your own tools, keyed by a name of your choice. The first track() in the process that passes exporters
     *        sets them up for every server; later calls change nothing.
     * @param bool $disableDiagnostics Turns off diagnostics: AgentCat's own log lines (the lines of agentcat.log), sent to
     *        AgentCat to find broken installs. The lines can contain session IDs, actor IDs, tool names and error messages.
     *        The first track() in the process decides. DISABLE_DIAGNOSTICS also turns them off, or on with false; they are
     *        off in test environments.
     * @param bool $disableSignalHandlers Keeps AgentCat from installing its SIGTERM and SIGINT handlers in CLI servers, so a
     *        signal ends the process as it did before, without the last delivery. Needed when the process must keep an
     *        ignored signal that it inherited. Applies only until the handlers are installed, at the first event or log
     *        line: set it on the first track() of the process. It is process-wide, and the first track() that installs the
     *        handlers decides; a later call with the switch only logs a warning. Default: false.
     */
    public function __construct(
        public bool $enableReportMissing = true,
        public bool $enableTracing = true,
        public bool $enableToolCallContext = true,
        public ?string $customContextDescription = null,
        public bool $enableAgentTracking = false,
        public ?\Closure $resolveSessionId = null,
        public ?\Closure $identify = null,
        public ?\Closure $redactSensitiveInformation = null,
        public ?\Closure $redactEvent = null,
        public ?string $apiBaseUrl = null,
        public ?\Closure $eventTags = null,
        public ?\Closure $eventProperties = null,
        public ?array $exporters = null,
        public bool $disableDiagnostics = false,
        public bool $disableSignalHandlers = false,
    ) {}

    /**
     * Builds options from an array with the argument names as keys (camelCase only).
     *
     * An unknown key, or a value of the wrong type, logs one warning and the default is used. The bool options
     * reject an explicit null too. A callable that is not a closure is converted to one.
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                Log::write(sprintf('WARN: Unknown AgentCat option "%s" ignored', $key));
            }
        }

        return new self(
            enableReportMissing: self::bool($config, 'enableReportMissing', true),
            enableTracing: self::bool($config, 'enableTracing', true),
            enableToolCallContext: self::bool($config, 'enableToolCallContext', true),
            customContextDescription: self::string($config, 'customContextDescription'),
            enableAgentTracking: self::bool($config, 'enableAgentTracking', false),
            resolveSessionId: self::closure($config, 'resolveSessionId'),
            identify: self::closure($config, 'identify'),
            redactSensitiveInformation: self::closure($config, 'redactSensitiveInformation'),
            redactEvent: self::closure($config, 'redactEvent'),
            apiBaseUrl: self::string($config, 'apiBaseUrl'),
            eventTags: self::closure($config, 'eventTags'),
            eventProperties: self::closure($config, 'eventProperties'),
            exporters: self::exporters($config),
            disableDiagnostics: self::bool($config, 'disableDiagnostics', false),
            disableSignalHandlers: self::bool($config, 'disableSignalHandlers', false),
        );
    }

    /** @param array<array-key, mixed> $config */
    private static function bool(array $config, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }
        if (is_bool($config[$key])) {
            return $config[$key];
        }
        self::reject($key, 'bool', $config[$key]);

        return $default;
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, OtlpExporter|DatadogExporter|SentryExporter|PostHogExporter>|null
     */
    private static function exporters(array $config): ?array
    {
        $value = $config['exporters'] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return ExporterConfigs::fromArray($value);
        }
        self::reject('exporters', 'array or null', $value);

        return null;
    }

    /** @param array<array-key, mixed> $config */
    private static function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }
        self::reject($key, 'string or null', $value);

        return null;
    }

    /**
     * The helper cannot know the signature of each hook. It declares a closure type that PHPStan accepts for every
     * hook parameter; the constructor documents the real signature of each hook.
     * It is private and its result only feeds the constructor, so the `never` return type reaches no caller.
     *
     * @param array<array-key, mixed> $config
     *
     * @return (\Closure(mixed ...): never)|null
     */
    private static function closure(array $config, string $key): ?\Closure
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value instanceof \Closure) {
            return $value;
        }
        if (is_callable($value)) {
            // @phpstan-ignore return.type (the constructor parameter that receives the closure checks the hook signature)
            return $value(...);
        }
        self::reject($key, 'callable or null', $value);

        return null;
    }

    private static function reject(string $key, string $expected, mixed $value): void
    {
        Log::write(sprintf('WARN: AgentCat option "%s" must be %s, got %s; the default is used', $key, $expected, get_debug_type($value)));
    }
}
