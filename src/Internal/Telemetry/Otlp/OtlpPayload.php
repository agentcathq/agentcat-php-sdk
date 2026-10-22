<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Otlp;

use AgentCat\Event;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Telemetry\JsError;
use AgentCat\Internal\Telemetry\TraceContext;
use AgentCat\Internal\Transport\HttpRequest;

/**
 * TypeScript `OTLPExporter` request: one span per event, OTLP/HTTP JSON, string attributes only.
 *
 * @internal
 */
final readonly class OtlpPayload
{
    /** 2^63 as a float: from here on `(int)` of a float is out of range. */
    private const float INT_LIMIT = 9223372036854775808.0;

    /** @param array<string, string> $headers */
    private function __construct(
        public string $url,
        public array $headers,
    ) {}

    public static function of(OtlpExporter $config): self
    {
        $url = rtrim($config->endpoint, '/');
        $headers = ['Content-Type' => 'application/json'];
        foreach ($config->headers as $name => $value) {
            $headers[$name] = $value; // TS spread: a user Content-Type replaces ours
        }

        return new self(str_ends_with($url, '/v1/traces') ? $url : $url . '/v1/traces', $headers);
    }

    /** @throws JsError a truthy duration that is not an integer (B04, D15) */
    public function request(Event $event): HttpRequest
    {
        $startMillis = Values::epochMillis($event->timestamp);
        $attributes = [];
        $add = static function (string $key, mixed $value) use (&$attributes): void {
            if (Js::truthy($value)) { // TS filters attributes whose stringValue is falsy
                $attributes[] = ['key' => $key, 'value' => ['stringValue' => $value]];
            }
        };
        $add('source', Constants::SOURCE);
        $add('mcp.event_type', $event->eventType);
        $add('mcp.session_id', $event->sessionId);
        $add('mcp.project_id', $event->projectId);
        $add('mcp.resource_name', $event->resourceName);
        $add('mcp.user_intent', $event->userIntent);
        $add('mcp.actor_id', $event->identifyActorGivenId);
        $add('mcp.actor_name', $event->identifyActorName);
        $add('mcp.client_name', $event->clientName);
        $add('mcp.client_version', $event->clientVersion);
        foreach (Values::entries($event->tags ?? []) as $key => $value) {
            $add('agentcat.tag.' . $key, $value);
        }
        if (Js::truthy($event->properties)) {
            $add('agentcat.properties', ExportJson::encode(ExportJson::map($event->properties)));
        }
        $span = [
            'traceId' => TraceContext::traceId($event->sessionId),
            'spanId' => TraceContext::spanId($event->id),
            'name' => $event->eventType !== '' ? $event->eventType : 'mcp.event',
            'kind' => 2, // SPAN_KIND_SERVER
            'startTimeUnixNano' => self::nanos($startMillis),
            'endTimeUnixNano' => self::endNanos($startMillis, $event->duration),
            'attributes' => $attributes,
            'status' => ['code' => Js::truthy($event->isError) ? 2 : 1],
        ];
        $body = ['resourceSpans' => [[
            'resource' => ['attributes' => [
                ['key' => 'service.name', 'value' => ['stringValue' => Js::truthy($event->serverName) ? $event->serverName : 'mcp-server']],
                ['key' => 'service.version', 'value' => ['stringValue' => Js::truthy($event->serverVersion) ? $event->serverVersion : 'unknown']],
            ]],
            'scopeSpans' => [['scope' => ['name' => Constants::SOURCE, 'version' => Js::truthy($event->agentcatVersion) ? $event->agentcatVersion : 'unknown'], 'spans' => [$span]]],
        ]]];

        return new HttpRequest($this->url, $this->headers, ExportJson::encode($body));
    }

    /**
     * TS `start + BigInt(duration) * 1e6` when the duration is truthy. Exact in integers while the end fits an int;
     * beyond that float arithmetic (spec conflict 7).
     *
     * @throws JsError a truthy duration that is not an integer
     */
    public static function endNanos(int $startMillis, int|float|null $duration): string
    {
        if ($duration === null || !Js::truthy($duration)) {
            return self::nanos($startMillis);
        }
        if (is_float($duration) && fmod($duration, 1.0) !== 0.0) { // also INF, whose fmod is NaN
            throw new JsError('RangeError', sprintf('The number %s cannot be converted to a BigInt because it is not an integer', Js::string($duration)));
        }
        $end = abs($duration) >= self::INT_LIMIT ? $startMillis + $duration : $startMillis + (int) $duration;
        if (is_int($end)) {
            return self::nanos($end);
        }
        $text = sprintf('%.0f', $end);

        return $text === '0' ? '0' : $text . '000000';
    }

    private static function nanos(int $millis): string
    {
        return $millis === 0 ? '0' : $millis . '000000';
    }
}
