<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\PostHog;

use AgentCat\Event;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Id\UuidV7;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Transport\HttpRequest;

/**
 * TypeScript `PostHogExporter` batch: capture, then `$exception` for failed calls, then `$ai_span` for tool calls
 * with AI tracing. Customer tags, then properties, are spread last and can replace anything.
 *
 * @internal
 */
final readonly class PostHogPayload
{
    private const array NAMES = [
        'mcp:tools/call' => 'mcp_tool_call', 'mcp:tools/list' => 'mcp_tools_list', 'mcp:initialize' => 'mcp_initialize',
        'mcp:resources/read' => 'mcp_resource_read', 'mcp:resources/list' => 'mcp_resources_list',
        'mcp:prompts/get' => 'mcp_prompt_get', 'mcp:prompts/list' => 'mcp_prompts_list',
    ];

    private function __construct(
        public string $batchUrl,
        private PostHogExporter $config,
    ) {}

    public static function of(PostHogExporter $config): self
    {
        $host = $config->host !== null && $config->host !== '' ? $config->host : 'https://us.i.posthog.com';

        return new self((str_ends_with($host, '/') ? substr($host, 0, -1) : $host) . '/batch', $config);
    }

    /** @return list<\stdClass> */
    public function batch(Event $event): array
    {
        $toolCall = $event->eventType === Constants::TOOLS_CALL_EVENT_TYPE;
        $capture = new \stdClass();
        $capture->source = Constants::SOURCE;
        if ($event->sessionId !== '') {
            $capture->{'$session_id'} = UuidV7::fromPrefixedId($event->sessionId);
        }
        $this->resource($capture, $event, $toolCall);
        if ($event->duration !== null) {
            $capture->duration_ms = $event->duration;
        }
        foreach (['server_name' => $event->serverName, 'server_version' => $event->serverVersion, 'client_name' => $event->clientName, 'client_version' => $event->clientVersion, 'project_id' => $event->projectId, 'user_intent' => $event->userIntent] as $key => $value) {
            if (Js::truthy($value)) {
                $capture->{$key} = $value;
            }
        }
        if ($event->isError !== null) {
            $capture->is_error = $event->isError;
        }
        if ($event->parameters !== null) {
            $capture->parameters = $event->parameters;
        }
        if ($event->response !== null) {
            $capture->response = $event->response;
        }
        $set = new \stdClass();
        if (Js::truthy($event->identifyActorName)) {
            $set->name = $event->identifyActorName;
        }
        $this->spread($set, $event->identifyActorData);
        if (get_object_vars($set) !== []) {
            $capture->{'$set'} = $set;
        }
        $this->spread($capture, $event->tags);
        $this->spread($capture, $event->properties);
        $batch = [$this->item($event, self::eventName($event->eventType), $capture)];

        if (Js::truthy($event->isError) && Js::truthy($event->error)) {
            $exception = new \stdClass();
            $exception->{'$exception_source'} = 'backend';
            if ($event->sessionId !== '') {
                $exception->{'$session_id'} = UuidV7::fromPrefixedId($event->sessionId);
            }
            if (Values::isObjectLike($event->error)) {
                foreach (['message' => '$exception_message', 'type' => '$exception_type', 'stack' => '$exception_stacktrace'] as $key => $property) {
                    $value = Values::get($event->error, $key);
                    if (Js::truthy($value)) {
                        $exception->{$property} = $value;
                    }
                }
            }
            $this->resource($exception, $event, $toolCall);
            foreach (['server_name' => $event->serverName, 'server_version' => $event->serverVersion, 'client_name' => $event->clientName, 'client_version' => $event->clientVersion] as $key => $value) {
                if (Js::truthy($value)) {
                    $exception->{$key} = $value;
                }
            }
            $batch[] = $this->item($event, '$exception', $exception);
        }

        if ($this->config->enableAITracing && $toolCall) {
            $span = new \stdClass();
            $span->{'$ai_trace_id'} = UuidV7::fromPrefixedId($event->sessionId !== '' ? $event->sessionId : $event->id);
            $span->{'$ai_span_id'} = UuidV7::fromPrefixedId($event->id);
            $span->{'$ai_span_name'} = Js::truthy($event->resourceName) ? $event->resourceName : 'unknown_tool';
            $span->{'$ai_is_error'} = $event->isError === true;
            $span->source = Constants::SOURCE;
            if ($event->sessionId !== '') {
                $span->{'$ai_session_id'} = 'agentcat_' . $event->sessionId;
                $span->{'$session_id'} = UuidV7::fromPrefixedId($event->sessionId);
            }
            if ($event->duration !== null) {
                $span->{'$ai_latency'} = $event->duration / 1000;
            }
            if (Js::truthy($event->isError) && Js::truthy($event->error)) {
                $span->{'$ai_error'} = $event->error;
            }
            if ($event->parameters !== null) {
                $span->{'$ai_input_state'} = $event->parameters;
            }
            if ($event->response !== null) {
                $span->{'$ai_output_state'} = $event->response;
            }
            foreach (['server_name' => $event->serverName, 'client_name' => $event->clientName] as $key => $value) {
                if (Js::truthy($value)) {
                    $span->{$key} = $value;
                }
            }
            $this->spread($span, $event->tags);
            $this->spread($span, $event->properties);
            $batch[] = $this->item($event, '$ai_span', $span);
        }

        return $batch;
    }

    /** @param list<\stdClass> $batch */
    public function request(array $batch): HttpRequest
    {
        return new HttpRequest($this->batchUrl, ['Content-Type' => 'application/json'], ExportJson::encode(['api_key' => $this->config->apiKey, 'batch' => $batch]));
    }

    public static function eventName(string $eventType): string
    {
        return self::NAMES[$eventType] ?? 'mcp_' . str_replace('/', '_', preg_replace('/\Amcp:/', '', $eventType) ?? $eventType);
    }

    private function item(Event $event, string $name, \stdClass $properties): \stdClass
    {
        $item = new \stdClass();
        $item->event = $name;
        $item->distinct_id = Js::truthy($event->identifyActorGivenId) ? $event->identifyActorGivenId : ($event->sessionId !== '' ? $event->sessionId : 'anonymous');
        $item->properties = $properties;
        $item->timestamp = Values::isoDate($event->timestamp);
        $item->type = 'capture';

        return $item;
    }

    private function resource(\stdClass $properties, Event $event, bool $toolCall): void
    {
        if (Js::truthy($event->resourceName)) {
            $properties->resource_name = $event->resourceName;
            if ($toolCall) {
                $properties->tool_name = $event->resourceName;
            }
        }
    }

    /** TS `for (const [k, v] of Object.entries(map)) target[k] = v` for a truthy map. */
    private function spread(\stdClass $target, mixed $map): void
    {
        if (!Js::truthy($map) || !Values::isObjectLike($map)) {
            return;
        }
        foreach (Values::entries($map) as $key => $value) {
            $target->{$key} = $value;
        }
    }
}
