<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Sentry;

use AgentCat\Event;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Telemetry\TraceContext;

/**
 * TypeScript `SentryExporter` envelopes: a log always, a transaction with `enableTracing`, an error event for failed
 * calls. Sentry's `timestamp` is the end of the transaction (B05, D15).
 *
 * @internal
 */
final readonly class SentryPayload
{
    private function __construct(
        public string $endpoint,
        public bool $enableTracing,
        private string $auth,
        private SentryExporter $config,
    ) {}

    /** @throws \InvalidArgumentException */
    public static function of(SentryExporter $config): self
    {
        $dsn = SentryDsn::parse($config->dsn);

        return new self($dsn->endpoint, $config->enableTracing, 'Sentry sentry_version=7, sentry_client=agentcat/1.0.0, sentry_key=' . $dsn->publicKey, $config);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['X-Sentry-Auth' => $this->auth, 'Content-Type' => 'application/x-sentry-envelope'];
    }

    public function logEnvelope(Event $event, string $sentAt): string
    {
        $eventId = TraceContext::spanId($event->id) . TraceContext::spanId($event->id);
        $attributes = new \stdClass();
        $strings = [
            'eventType' => $event->eventType, 'resourceName' => $event->resourceName, 'serverName' => $event->serverName,
            'clientName' => $event->clientName, 'sessionId' => $event->sessionId, 'projectId' => $event->projectId,
        ];
        foreach ($strings as $key => $value) {
            if (Js::truthy($value)) {
                $attributes->{$key} = ['value' => $value, 'type' => 'string'];
            }
        }
        if ($event->duration !== null) {
            $attributes->duration_ms = ['value' => $event->duration, 'type' => 'double'];
        }
        foreach (['actorId' => $event->identifyActorGivenId, 'actorName' => $event->identifyActorName, 'userIntent' => $event->userIntent, 'serverVersion' => $event->serverVersion, 'clientVersion' => $event->clientVersion] as $key => $value) {
            if (Js::truthy($value)) {
                $attributes->{$key} = ['value' => $value, 'type' => 'string'];
            }
        }
        if ($event->isError !== null) {
            $attributes->isError = ['value' => $event->isError, 'type' => 'boolean'];
        }
        $type = $event->eventType !== '' ? $event->eventType : 'event';
        $log = [
            'timestamp' => $this->seconds($event),
            'trace_id' => TraceContext::traceId($event->sessionId),
            'event_id' => $eventId,
            'level' => Js::truthy($event->isError) ? 'error' : 'info',
            'body' => Js::truthy($event->resourceName) ? "MCP {$type}: {$event->resourceName}" : "MCP {$type}",
            'attributes' => $attributes,
        ];

        return ExportJson::encode(['event_id' => $eventId, 'sent_at' => $sentAt]) . "\n"
            . ExportJson::encode(['type' => 'log', 'item_count' => 1, 'content_type' => 'application/vnd.sentry.items.log+json']) . "\n"
            . ExportJson::encode(['items' => [$log]]) . "\n"; // the trailing newline exists on the log envelope only
    }

    public function transaction(Event $event): SentryTransaction
    {
        $end = $this->seconds($event);
        $eventId = TraceContext::spanId($event->id) . TraceContext::spanId('');
        $traceId = TraceContext::traceId($event->sessionId);
        $spanId = TraceContext::spanId($event->id);
        $op = $event->eventType !== '' ? $event->eventType : 'mcp.event';
        $name = $this->name($event);
        $body = new \stdClass();
        $body->type = 'transaction';
        $body->event_id = $eventId;
        $duration = $event->duration;
        $body->timestamp = $end;
        $body->start_timestamp = $duration !== null && Js::truthy($duration) ? $end - $duration / 1000 : $end; // B05: timestamp is the end
        $body->transaction = $name;
        $body->contexts = $this->contexts($event, ['trace_id' => $traceId, 'span_id' => $spanId, 'op' => $op, 'status' => Js::truthy($event->isError) ? 'internal_error' : 'ok']);
        $body->tags = $this->tags($event);
        $body->extra = $this->extra($event);

        return new SentryTransaction($eventId, $traceId, $spanId, $op, $end, $name, $body);
    }

    public function transactionEnvelope(SentryTransaction $transaction, string $sentAt): string
    {
        return ExportJson::encode(['event_id' => $transaction->eventId, 'sent_at' => $sentAt]) . "\n"
            . ExportJson::encode(['type' => 'transaction']) . "\n"
            . ExportJson::encode($transaction->body);
    }

    /** @return array{eventId: string, body: \stdClass} */
    public function errorEvent(Event $event, ?SentryTransaction $transaction): array
    {
        $message = 'Unknown error';
        $type = 'ToolCallError';
        if (is_string($event->error) && $event->error !== '') {
            $message = $event->error;
        } elseif (Js::truthy($event->error) && Values::isObjectLike($event->error)) {
            $message = Values::has($event->error, 'message') ? Js::string(Values::get($event->error, 'message')) : ExportJson::encode($event->error);
            if (Values::has($event->error, 'type')) {
                $type = Js::string(Values::get($event->error, 'type'));
            }
        }
        $trace = ['trace_id' => $transaction->traceId ?? TraceContext::traceId($event->sessionId), 'span_id' => TraceContext::spanId($event->id)];
        if ($transaction instanceof SentryTransaction) {
            $trace['parent_span_id'] = $transaction->spanId; // the same value as span_id (TS self-parent quirk)
        }
        $trace['op'] = $transaction->op ?? ($event->eventType !== '' ? $event->eventType : 'mcp.event');
        $contexts = $this->contexts($event, $trace);
        $mcp = new \stdClass();
        foreach (['resource_name' => $event->resourceName, 'session_id' => $event->sessionId, 'event_type' => $event->eventType, 'user_intent' => $event->userIntent] as $key => $value) {
            if ($value !== null) {
                $mcp->{$key} = $value;
            }
        }
        $contexts->mcp = $mcp;
        $eventId = TraceContext::spanId($event->id) . TraceContext::spanId('');
        $body = new \stdClass();
        $body->type = 'event';
        $body->event_id = $eventId;
        $body->timestamp = $transaction->timestamp ?? $this->seconds($event);
        $body->level = 'error';
        $body->exception = ['values' => [['type' => $type, 'value' => $message, 'mechanism' => ['type' => 'mcp_tool_call', 'handled' => false]]]];
        $body->contexts = $contexts;
        $body->tags = $this->tags($event);
        $body->extra = $this->extra($event);
        $body->transaction = $transaction->name ?? $this->name($event);

        return ['eventId' => $eventId, 'body' => $body];
    }

    /** @param array{eventId: string, body: \stdClass} $errorEvent */
    public function errorEnvelope(array $errorEvent, string $sentAt): string
    {
        return ExportJson::encode(['event_id' => $errorEvent['eventId'], 'sent_at' => $sentAt]) . "\n"
            . ExportJson::encode(['type' => 'event', 'content_type' => 'application/json']) . "\n"
            . ExportJson::encode($errorEvent['body']);
    }

    private function tags(Event $event): \stdClass
    {
        $tags = new \stdClass();
        $tags->source = Constants::SOURCE;
        $fields = [
            'environment' => $this->config->environment, 'release' => $this->config->release, 'event_type' => $event->eventType,
            'resource' => $event->resourceName, 'server_name' => $event->serverName, 'client_name' => $event->clientName, 'actor_id' => $event->identifyActorGivenId,
        ];
        foreach ($fields as $key => $value) {
            if (Js::truthy($value)) {
                $tags->{$key} = $value;
            }
        }
        foreach (Values::entries($event->tags ?? []) as $key => $value) {
            $tags->{'agentcat.' . $key} = $value; // not sanitized (D15)
        }

        return $tags;
    }

    private function extra(Event $event): \stdClass
    {
        $extra = new \stdClass();
        foreach (['session_id' => $event->sessionId, 'project_id' => $event->projectId, 'user_intent' => $event->userIntent, 'actor_name' => $event->identifyActorName, 'server_version' => $event->serverVersion, 'client_version' => $event->clientVersion] as $key => $value) {
            if (Js::truthy($value)) {
                $extra->{$key} = $value;
            }
        }
        if ($event->duration !== null) {
            $extra->duration_ms = $event->duration;
        }
        if (Js::truthy($event->error)) {
            $extra->error = $event->error;
        }

        return $extra;
    }

    /** @param array<string, string> $trace */
    private function contexts(Event $event, array $trace): \stdClass
    {
        $contexts = new \stdClass();
        $contexts->trace = $trace;
        if (Js::truthy($event->properties)) {
            $contexts->agentcat = ExportJson::map($event->properties);
        }

        return $contexts;
    }

    private function name(Event $event): string
    {
        if (Js::truthy($event->resourceName)) {
            return ($event->eventType !== '' ? $event->eventType : 'mcp') . ' - ' . $event->resourceName;
        }

        return $event->eventType !== '' ? $event->eventType : 'mcp.event';
    }

    private function seconds(Event $event): int|float
    {
        return Values::epochMillis($event->timestamp) / 1000; // JS ms / 1000: an int when it divides
    }
}
