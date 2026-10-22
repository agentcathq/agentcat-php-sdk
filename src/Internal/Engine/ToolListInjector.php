<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Tools\GetMoreTools;

/**
 * Port of the TypeScript `buildInjectedList`: adds `session_id`, `agent_id` and `context` to each listed tool's
 * input schema, appends get_more_tools, and declares `mcp_session` in output schemas.
 *
 * @internal
 */
final class ToolListInjector
{
    public const string NO_TOOLS_WARNING = 'Warning: No tools found in the original list. This is likely due to the tools not being registered before AgentCat.track().';

    private const string UNKNOWN_TOOL = 'unknown';

    private const string SHADOW_WARNING = 'WARN: This server registers its own "get_more_tools" tool, which is shadowed by AgentCat\'s report-missing tool: calls to it are answered by AgentCat and never reach your handler. Rename your tool, or set enableReportMissing: false to keep yours reachable.';

    private const string CONFLICT_ERROR = 'ERROR: Tool "%s" already declares a \'session_id\' parameter. AgentCat will not inject its own, and calls to this tool are published without a session, so they cannot be correlated. Your parameter is untouched and still reaches your handler. If you already manage sessions, pass a resolveSessionId hook to track() — AgentCat will derive its session from your identifier and stop injecting session_id entirely.';

    /** @var array<array-key, list<string>> keyed by tool name */
    private array $registry = [];

    /** @var list<string> */
    private array $outputRegistry = [];

    private function __construct(
        private readonly InjectionConfig $config,
        private readonly InjectionState $state,
    ) {}

    /**
     * Never throws. The input is never mutated; changed tools are deep copies.
     *
     * @param list<mixed> $tools the `tools` of a `tools/list` result
     */
    public static function inject(array $tools, InjectionConfig $config, InjectionState $state): InjectedList
    {
        try {
            return (new self($config, $state))->build($tools);
        } catch (\Throwable $throwable) {
            ToolListing::injectionFailed($throwable);

            return new InjectedList($tools, [], [], degraded: true);
        }
    }

    /**
     * @param list<mixed> $tools
     */
    private function build(array $tools): InjectedList
    {
        // Appended before injection, so get_more_tools receives the handles too.
        $result = $this->appendGetMoreTools($tools);
        // Handles first, then context: { ...customer properties, session_id, agent_id, context }.
        if ($this->config->injectSessionId() || $this->config->injectAgentId()) {
            $result = array_map($this->injectHandles(...), $result);
        }
        if ($this->config->enableToolCallContext) {
            $result = array_map($this->injectContext(...), $result);
        }
        // Listed tools without injections still get an entry: the call-time strip fallback is for unlisted tools only.
        foreach ($result as $tool) {
            $name = $this->nameOf($tool);
            if ($name !== null && !array_key_exists($name, $this->registry)) {
                $this->registry[$name] = [];
            }
        }

        return new InjectedList($result, $this->registry, $this->outputRegistry);
    }

    /**
     * @param list<mixed> $tools
     * @return list<mixed>
     */
    private function appendGetMoreTools(array $tools): array
    {
        if (!$this->config->enableReportMissing) {
            return $tools;
        }
        foreach ($tools as $tool) {
            if ($this->nameOf($tool) === GetMoreTools::NAME) {
                // The customer's tool stays listed, but AgentCat answers its calls.
                if ($this->state->warnShadowOnce()) {
                    Log::write(self::SHADOW_WARNING);
                }

                return $tools;
            }
        }
        $tools[] = GetMoreTools::descriptor();

        return $tools;
    }

    private function injectHandles(mixed $tool): mixed
    {
        $name = $this->toolName($tool);
        try {
            return $this->injectHandlesInto($tool, $name);
        } catch (\Throwable $throwable) {
            // One tool must never break the listing: list it as is and drop what was recorded for it.
            unset($this->registry[$name]);
            $this->outputRegistry = array_values(array_diff($this->outputRegistry, [$name]));
            Log::write(sprintf('WARN: Handle injection failed for tool "%s"; listing it unmodified - %s', $name, ErrorText::message($throwable)));

            return $tool;
        }
    }

    private function injectHandlesInto(mixed $tool, string $name): \stdClass
    {
        $tool = $this->requireObject($tool, 'the tool');
        $listedSchema = $tool->inputSchema ?? null;
        if ($listedSchema instanceof \stdClass && $this->isComposed($listedSchema)) {
            // Not injectable, but a root session_id is still the customer's parameter, not ours to read.
            if ($this->config->injectSessionId() && $this->declaresProperty($listedSchema, Constants::PARAM_SESSION_ID)) {
                $this->state->markDeclaredSessionParam($name);
            }
            Log::write(sprintf('WARN: Tool "%s" has complex schema (oneOf/allOf/anyOf). Skipping handle injection.', $name));

            return $tool;
        }

        $copy = $this->copyTool($tool);
        [$schema, $properties] = $this->prepareInputSchema($copy);

        if ($this->config->injectSessionId()) {
            if (Js::truthy($properties->{Constants::PARAM_SESSION_ID} ?? null)) {
                $this->state->markDeclaredSessionParam($name);
                if ($this->state->reportConflictOnce($name)) {
                    Log::write(sprintf(self::CONFLICT_ERROR, $name));
                }
            } else {
                $properties->{Constants::PARAM_SESSION_ID} = (object) [
                    'type' => 'string',
                    'description' => Constants::SESSION_ID_PARAM_DESCRIPTION,
                    'pattern' => Constants::SESSION_ID_PARAM_PATTERN,
                ];
                $this->record($name, Constants::PARAM_SESSION_ID);
                $this->addToRequired($schema, Constants::PARAM_SESSION_ID);
            }
        }

        if ($this->config->injectAgentId()) {
            if (Js::truthy($properties->{Constants::PARAM_AGENT_ID} ?? null)) {
                Log::write(sprintf('WARN: Tool "%s" already has \'agent_id\' parameter. Skipping agent_id injection.', $name));
            } else {
                $properties->{Constants::PARAM_AGENT_ID} = (object) [
                    'type' => 'string',
                    'description' => Constants::AGENT_ID_PARAM_DESCRIPTION,
                ];
                $this->record($name, Constants::PARAM_AGENT_ID);
                $this->addToRequired($schema, Constants::PARAM_AGENT_ID);
            }
        }

        $this->injectOutputSchema($copy, $name);

        return $copy;
    }

    /** Declares the optional `mcp_session` result field, so validating clients accept the mirrored mint-back. */
    private function injectOutputSchema(\stdClass $tool, string $name): void
    {
        $schema = $tool->outputSchema ?? null;
        if (!Js::truthy($schema)) {
            return;
        }
        $schema = $this->objectOrEmptyList($schema, 'outputSchema');
        $tool->outputSchema = $schema;
        if ($this->isComposed($schema)) {
            Log::write(sprintf('WARN: Tool "%s" has complex outputSchema (oneOf/allOf/anyOf). Skipping mcp_session injection; mint-back stays content-only for this tool.', $name));

            return;
        }
        $properties = $this->propertiesOf($schema, 'outputSchema.properties');
        if (Js::truthy($properties->{Constants::MCP_SESSION_KEY} ?? null)) {
            Log::write(sprintf('WARN: Tool "%s" already declares \'mcp_session\' in outputSchema. Skipping injection.', $name));

            return;
        }
        $properties->{Constants::MCP_SESSION_KEY} = $this->mcpSessionDeclaration();
        if (!in_array($name, $this->outputRegistry, true)) {
            $this->outputRegistry[] = $name;
        }
    }

    /** Announces only the states the agent can receive: no session_id or status in hook mode, no agent_id untracked. */
    private function mcpSessionDeclaration(): \stdClass
    {
        $properties = new \stdClass();
        if ($this->config->injectSessionId()) {
            $properties->session_id = (object) [
                'type' => 'string',
                'description' => Constants::MCP_SESSION_SESSION_ID_DESCRIPTION,
            ];
        }
        if ($this->config->injectAgentId()) {
            $properties->agent_id = (object) [
                'type' => 'string',
                'description' => Constants::MCP_SESSION_AGENT_ID_DESCRIPTION,
            ];
        }
        if ($this->config->injectSessionId()) {
            $properties->status = (object) [
                'type' => 'string',
                'enum' => ['issued', 'active', 'unrecognized'],
                'description' => Constants::MCP_SESSION_STATUS_DESCRIPTION,
            ];
        }

        return (object) [
            'type' => 'object',
            'description' => $this->config->injectSessionId()
                ? Constants::MCP_SESSION_FIELD_DESCRIPTION
                : Constants::MCP_SESSION_FIELD_DESCRIPTION_HOOK_MODE,
            'properties' => $properties,
        ];
    }

    private function injectContext(mixed $tool): mixed
    {
        // get_more_tools (AgentCat's or the customer's) has its own context parameter.
        if ($this->nameOf($tool) === GetMoreTools::NAME) {
            return $tool;
        }
        $name = $this->toolName($tool);
        try {
            return $this->injectContextInto($tool, $name);
        } catch (\Throwable $throwable) {
            if (isset($this->registry[$name])) {
                $this->registry[$name] = array_values(array_diff($this->registry[$name], [Constants::PARAM_CONTEXT]));
            }
            Log::write(sprintf('WARN: Context injection failed for tool "%s"; listing it unmodified - %s', $name, ErrorText::message($throwable)));

            return $tool;
        }
    }

    private function injectContextInto(mixed $tool, string $name): \stdClass
    {
        $tool = $this->requireObject($tool, 'the tool');
        $listedSchema = $tool->inputSchema ?? null;
        if ($listedSchema instanceof \stdClass) {
            if ($this->declaresProperty($listedSchema, Constants::PARAM_CONTEXT)) {
                Log::write(sprintf('WARN: Tool "%s" already has \'context\' parameter. Skipping context injection.', $name));

                return $tool;
            }
            if ($this->isComposed($listedSchema)) {
                Log::write(sprintf('WARN: Tool "%s" has complex schema (oneOf/allOf/anyOf). Skipping context injection.', $name));

                return $tool;
            }
        }

        $copy = $this->copyTool($tool);
        [$schema, $properties] = $this->prepareInputSchema($copy);
        $properties->{Constants::PARAM_CONTEXT} = (object) [
            'type' => 'string',
            'description' => $this->contextDescription(),
        ];
        $this->record($name, Constants::PARAM_CONTEXT);
        $this->addToRequired($schema, Constants::PARAM_CONTEXT);

        return $copy;
    }

    /** JS `customContextDescription || DEFAULT`: an empty string falls back to the default. */
    private function contextDescription(): string
    {
        $custom = $this->config->customContextDescription;

        return $custom === null || $custom === '' ? Constants::DEFAULT_CONTEXT_PARAMETER_DESCRIPTION : $custom;
    }

    private function record(string $tool, string $param): void
    {
        $params = $this->registry[$tool] ?? [];
        if (!in_array($param, $params, true)) {
            $params[] = $param;
        }
        $this->registry[$tool] = $params;
    }

    /**
     * Stands in for the TS `JSON.parse(JSON.stringify(...))` copy. It covers the whole tool, so a tool PHP cannot
     * encode (invalid UTF-8, NAN, nesting deeper than 512) fails here and is listed unmodified.
     */
    private function copyTool(\stdClass $tool): \stdClass
    {
        if (Json::encodeJs($tool) === null) {
            throw new \UnexpectedValueException('the tool cannot be encoded as JSON');
        }

        return $this->requireObject(Json::deepCopy($tool), 'the tool');
    }

    /**
     * A falsy schema becomes `{"type":"object","properties":{},"required":[]}`, a falsy `properties` becomes `{}`,
     * and `additionalProperties: false` is dropped. A non-empty list or a scalar where TS needs an object throws.
     *
     * @return array{\stdClass, \stdClass} the schema and its properties
     */
    private function prepareInputSchema(\stdClass $tool): array
    {
        $schema = $tool->inputSchema ?? null;
        if (!Js::truthy($schema)) {
            $schema = (object) ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
        }
        $schema = $this->objectOrEmptyList($schema, 'inputSchema');
        $tool->inputSchema = $schema;
        $properties = $this->propertiesOf($schema, 'inputSchema.properties');
        if (($schema->additionalProperties ?? null) === false) {
            unset($schema->additionalProperties);
        }

        return [$schema, $properties];
    }

    private function propertiesOf(\stdClass $schema, string $what): \stdClass
    {
        $properties = $schema->properties ?? null;
        $properties = Js::truthy($properties) ? $this->objectOrEmptyList($properties, $what) : new \stdClass();
        $schema->properties = $properties;

        return $properties;
    }

    /**
     * PHP divergence from TS: PHP encodes an empty map as `[]`, and PHP MCP libraries often send `"properties":[]`,
     * so an empty list counts as an empty object here. TS sets the properties on the JS array instead, where the
     * wire never shows them. A non-empty list or a scalar still throws.
     */
    private function objectOrEmptyList(mixed $value, string $what): \stdClass
    {
        return $this->requireObject($value === [] ? new \stdClass() : $value, $what);
    }

    /** Appends without duplicating; customer entries keep their order. A non-list `required` is replaced. */
    private function addToRequired(\stdClass $schema, string $param): void
    {
        $required = $schema->required ?? null;
        if (!is_array($required) || !array_is_list($required)) {
            $schema->required = [$param];

            return;
        }
        if (!in_array($param, $required, true)) {
            $required[] = $param;
            $schema->required = $required;
        }
    }

    /** JS truthiness: an empty list counts as present. */
    private function isComposed(\stdClass $schema): bool
    {
        return Js::truthy($schema->oneOf ?? null)
            || Js::truthy($schema->allOf ?? null)
            || Js::truthy($schema->anyOf ?? null);
    }

    private function declaresProperty(\stdClass $schema, string $property): bool
    {
        $properties = $schema->properties ?? null;

        return $properties instanceof \stdClass && Js::truthy($properties->{$property} ?? null);
    }

    private function requireObject(mixed $value, string $what): \stdClass
    {
        if (!$value instanceof \stdClass) {
            throw new \UnexpectedValueException($what . ' is not a JSON object');
        }

        return $value;
    }

    /** MCP requires a string name; anything else counts as nameless. */
    private function nameOf(mixed $tool): ?string
    {
        $name = $tool instanceof \stdClass ? ($tool->name ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function toolName(mixed $tool): string
    {
        return $this->nameOf($tool) ?? self::UNKNOWN_TOOL;
    }
}
