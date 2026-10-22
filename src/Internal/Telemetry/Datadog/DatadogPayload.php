<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Datadog;

use AgentCat\Event;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Telemetry\JsError;
use AgentCat\Internal\Telemetry\TraceContext;
use AgentCat\Internal\Transport\HttpRequest;

/**
 * TypeScript `DatadogExporter` requests: one log (HTTP logs intake v2) and up to three metric series (v1 series).
 *
 * @internal
 */
final readonly class DatadogPayload
{
    private function __construct(
        public string $logsUrl,
        public string $metricsUrl,
        private DatadogExporter $config,
    ) {}

    public static function of(DatadogExporter $config): self
    {
        $site = preg_replace('~\Ahttps?://~', '', $config->site) ?? $config->site;
        $site = str_ends_with($site, '/') ? substr($site, 0, -1) : $site;

        return new self("https://http-intake.logs.{$site}/api/v2/logs", "https://api.{$site}/api/v1/series", $config);
    }

    /**
     * @return array{logs: HttpRequest, metrics: HttpRequest, series: int}
     *
     * @throws JsError a tag value that is not a string (TS `value.replace`)
     */
    public function requests(Event $event): array
    {
        $millis = Values::epochMillis($event->timestamp);
        $type = $event->eventType !== '' ? str_replace('/', '.', $event->eventType) : null;
        $env = Js::truthy($this->config->env) ? 'env:' . $this->config->env : null;
        $resource = Js::truthy($event->resourceName) ? 'resource:' . $event->resourceName : null;

        $present = static fn(?string $tag): bool => $tag !== null;
        $tags = array_filter([$env, $type === null ? null : 'event_type:' . $type, $resource, Js::truthy($event->isError) ? 'error:true' : null], $present);
        $tags[] = 'source:' . Constants::SOURCE;
        foreach (Values::entries($event->tags ?? []) as $key => $value) {
            if (!is_string($value)) {
                throw new JsError('TypeError', 'value.replace is not a function');
            }
            $tags[] = 'agentcat.' . self::tagKey((string) $key) . ':' . str_replace(',', '_', $value);
        }
        $mcp = new \stdClass();
        $mcp->session_id = $event->sessionId;
        $mcp->event_id = $event->id;
        $mcp->event_type = $event->eventType;
        $optional = [
            'resource' => $event->resourceName, 'duration_ms' => $event->duration, 'user_intent' => $event->userIntent,
            'actor_id' => $event->identifyActorGivenId, 'actor_name' => $event->identifyActorName, 'client_name' => $event->clientName,
            'client_version' => $event->clientVersion, 'server_name' => $event->serverName, 'server_version' => $event->serverVersion,
            'is_error' => $event->isError, 'error' => $event->error, 'tags' => ExportJson::map($event->tags), 'properties' => ExportJson::map($event->properties),
        ];
        foreach ($optional as $key => $value) {
            if ($value !== null) { // JSON.stringify leaves undefined out (spec conflict 4)
                $mcp->{$key} = $value;
            }
        }
        $log = [
            'message' => sprintf('%s - %s', $event->eventType !== '' ? $event->eventType : 'unknown', Js::truthy($event->resourceName) ? $event->resourceName : 'unknown'),
            'service' => $this->config->service,
            'ddsource' => Constants::SOURCE,
            'ddtags' => implode(',', $tags),
            'timestamp' => $millis,
            'status' => Js::truthy($event->isError) ? 'error' : 'info',
            'dd' => ['trace_id' => TraceContext::datadogTraceId($event->sessionId), 'span_id' => TraceContext::datadogSpanId($event->id)],
            'mcp' => $mcp,
        ];
        if (Js::truthy($event->isError) && Js::truthy($event->error)) {
            $log['error'] = ['message' => is_string($event->error) ? $event->error : ExportJson::encode($event->error)];
        }

        $seconds = intdiv($millis, 1000) - ($millis % 1000 < 0 ? 1 : 0); // Math.floor
        $metricTags = array_values(array_filter(['service:' . $this->config->service, $env, $type === null ? null : 'event_type:' . $type, $resource], $present));
        $series = [['metric' => 'mcp.events.count', 'type' => 'count', 'points' => [[$seconds, 1]], 'tags' => $metricTags]];
        if (Js::truthy($event->duration)) {
            $series[] = ['metric' => 'mcp.event.duration', 'type' => 'gauge', 'points' => [[$seconds, $event->duration]], 'tags' => $metricTags];
        }
        if (Js::truthy($event->isError)) {
            $series[] = ['metric' => 'mcp.errors.count', 'type' => 'count', 'points' => [[$seconds, 1]], 'tags' => $metricTags];
        }
        $headers = ['DD-API-KEY' => $this->config->apiKey, 'Content-Type' => 'application/json'];

        return [
            'logs' => new HttpRequest($this->logsUrl, $headers, ExportJson::encode([$log])),
            'metrics' => new HttpRequest($this->metricsUrl, $headers, ExportJson::encode(['series' => $series])),
            'series' => count($series),
        ];
    }

    /** TS `key.toLowerCase().replace(/[\s:,]+/g, "_")`; invalid UTF-8 bytes become `?`. */
    public static function tagKey(string $key): string
    {
        return preg_replace('/(?:' . Js::WHITESPACE . '|[:,])+/u', '_', mb_strtolower($key, 'UTF-8')) ?? $key;
    }
}
