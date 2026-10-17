<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry\Sentry;

use AgentCat\Event;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Telemetry\Sentry\SentryPayload;
use AgentCat\Internal\Telemetry\Sentry\SentryTransaction;
use AgentCat\Internal\Telemetry\TraceContext;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SentryPayloadTest extends TestCase
{
    private const string DSN = 'https://abc123@o1.ingest.sentry.io/42';

    #[Group('ac-06-34')]
    public function testTheLogEnvelopeIsThreeLinesWithATrailingNewline(): void
    {
        $envelope = $this->payload()->logEnvelope(ExporterFixtures::event(), '2026-10-17T00:00:00.000Z');
        $lines = explode("\n", $envelope);

        $this->assertCount(4, $lines);
        $this->assertSame('', $lines[3]);
        $this->assertSame('{"event_id":"38fbea6b36e16f3738fbea6b36e16f37","sent_at":"2026-10-17T00:00:00.000Z"}', $lines[0]);
        $this->assertSame('{"type":"log","item_count":1,"content_type":"application/vnd.sentry.items.log+json"}', $lines[1]);
        $this->assertStringStartsWith('{"items":[{"timestamp":1749988805.123,', $lines[2]);
        $this->assertStringNotContainsString('"length"', $envelope);
    }

    #[Group('ac-06-34')]
    public function testTheTransactionEnvelopeHasNoTrailingNewlineAndTheTransactionItemHeader(): void
    {
        $payload = $this->payload(tracing: true);
        $transaction = $payload->transaction(ExporterFixtures::event());
        $envelope = $payload->transactionEnvelope($transaction, 'SENT');
        $lines = explode("\n", $envelope);

        $this->assertCount(3, $lines);
        $this->assertSame('{"event_id":"' . $transaction->eventId . '","sent_at":"SENT"}', $lines[0]);
        $this->assertSame('{"type":"transaction"}', $lines[1]);
        $this->assertSame(ExportJson::encode($transaction->body), $lines[2]);
        $this->assertStringNotContainsString('"length"', $envelope);
    }

    #[Group('ac-06-34')]
    public function testTheErrorEnvelopeHasNoTrailingNewlineAndTheEventItemHeader(): void
    {
        $payload = $this->payload();
        $error = $payload->errorEvent(ExporterFixtures::errorEvent(), null);
        $envelope = $payload->errorEnvelope($error, 'SENT');
        $lines = explode("\n", $envelope);

        $this->assertCount(3, $lines);
        $this->assertSame('{"event_id":"' . $error['eventId'] . '","sent_at":"SENT"}', $lines[0]);
        $this->assertSame('{"type":"event","content_type":"application/json"}', $lines[1]);
        $this->assertSame(ExportJson::encode($error['body']), $lines[2]);
        $this->assertStringNotContainsString('"length"', $envelope);
    }

    #[Group('ac-06-31')]
    public function testTracingFollowsTheConfiguration(): void
    {
        $this->assertTrue($this->payload(tracing: true)->enableTracing);
        $this->assertFalse($this->payload()->enableTracing);
    }

    public function testTheTransactionKeepsItsParts(): void
    {
        $event = ExporterFixtures::event();
        $transaction = $this->payload(tracing: true)->transaction($event);

        $this->assertSame(TraceContext::traceId(ExporterFixtures::SESSION), $transaction->traceId);
        $this->assertSame(TraceContext::spanId(ExporterFixtures::EVENT), $transaction->spanId);
        $this->assertSame('mcp:tools/call', $transaction->op);
        $this->assertEqualsWithDelta(1749988805.123, $transaction->timestamp, PHP_FLOAT_EPSILON);
        $this->assertSame('mcp:tools/call - get_weather', $transaction->name);
        $this->assertSame($transaction->eventId, JsonAssert::at($this->tree($transaction->body), 'event_id'));
        $this->assertSame('transaction', JsonAssert::at($this->tree($transaction->body), 'type'));
        $this->assertSame($transaction->name, JsonAssert::at($this->tree($transaction->body), 'transaction'));
    }

    public function testTheTransactionEventIdHasARandomSecondHalf(): void
    {
        $payload = $this->payload(tracing: true);
        $first = $payload->transaction(ExporterFixtures::event());
        $second = $payload->transaction(ExporterFixtures::event());

        $this->assertMatchesRegularExpression('/\A38fbea6b36e16f37[0-9a-f]{16}\z/', $first->eventId);
        $this->assertNotSame($first->eventId, $second->eventId);
    }

    public function testTheTransactionSpansTheDuration(): void
    {
        $tree = $this->tree($this->payload(tracing: true)->transaction(ExporterFixtures::event())->body);

        $this->assertEqualsWithDelta(1749988805.123, JsonAssert::at($tree, 'timestamp'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1749988804.9729998, JsonAssert::at($tree, 'start_timestamp'), PHP_FLOAT_EPSILON);
    }

    #[Group('ac-06-36')]
    public function testAZeroOrMissingDurationStartsWhereItEnds(): void
    {
        foreach ([0, null, 0.0, NAN] as $duration) {
            $event = ExporterFixtures::event();
            $event->duration = $duration;
            $tree = $this->tree($this->payload(tracing: true)->transaction($event)->body);

            $this->assertSame(JsonAssert::at($tree, 'timestamp'), JsonAssert::at($tree, 'start_timestamp'));
        }
    }

    #[Group('ac-06-36')]
    public function testAnEventWithAWholeSecondTimestampKeepsAnIntegerTimestamp(): void
    {
        $event = ExporterFixtures::event();
        $event->timestamp = new \DateTimeImmutable('2025-06-15T12:00:05.000Z');
        $event->duration = 2000;
        $body = ExportJson::encode($this->payload(tracing: true)->transaction($event)->body);

        $this->assertStringContainsString('"timestamp":1749988805,"start_timestamp":1749988803,', $body);
    }

    #[Group('ac-06-36')]
    public function testATimestampBeforeTheEpochIsNegative(): void
    {
        $event = ExporterFixtures::event();
        $event->timestamp = new \DateTimeImmutable('1969-12-31T23:59:58.500Z');
        $event->duration = 0;

        $this->assertSame(-1.5, $this->payload(tracing: true)->transaction($event)->timestamp);
    }

    #[Group('ac-06-36')]
    public function testTheTransactionStatusAndContexts(): void
    {
        $event = ExporterFixtures::event();
        $tree = $this->tree($this->payload(tracing: true)->transaction($event)->body);

        $this->assertSame('ok', JsonAssert::at($tree, 'contexts', 'trace', 'status'));
        $this->assertSame('mcp:tools/call', JsonAssert::at($tree, 'contexts', 'trace', 'op'));
        $this->assertSame(['trace', 'agentcat'], array_keys($this->vars(JsonAssert::at($tree, 'contexts'))));
        $this->assertSame('desktop', JsonAssert::at($tree, 'contexts', 'agentcat', 'device'));

        $event->isError = true;
        $tree = $this->tree($this->payload(tracing: true)->transaction($event)->body);
        $this->assertSame('internal_error', JsonAssert::at($tree, 'contexts', 'trace', 'status'));
    }

    #[Group('ac-06-36')]
    public function testErrorEventWithoutATransactionHasNoParentSpan(): void
    {
        $event = ExporterFixtures::errorEvent();
        $error = $this->payload()->errorEvent($event, null);
        $tree = $this->tree($error['body']);
        $trace = JsonAssert::at($tree, 'contexts', 'trace');

        $this->assertInstanceOf(\stdClass::class, $trace);
        $this->assertSame(['trace_id', 'span_id', 'op'], array_keys(get_object_vars($trace)));
        $this->assertSame(TraceContext::traceId(ExporterFixtures::SESSION), JsonAssert::at($trace, 'trace_id'));
        $this->assertSame(TraceContext::spanId(ExporterFixtures::EVENT), JsonAssert::at($trace, 'span_id'));
        $this->assertSame('mcp:tools/call', JsonAssert::at($trace, 'op'));
        $this->assertEqualsWithDelta(1749988805.123, JsonAssert::at($tree, 'timestamp'), PHP_FLOAT_EPSILON);
        $this->assertSame('mcp:tools/call - get_weather', JsonAssert::at($tree, 'transaction'));
        $this->assertSame('event', JsonAssert::at($tree, 'type'));
        $this->assertSame('error', JsonAssert::at($tree, 'level'));
        $this->assertSame($error['eventId'], JsonAssert::at($tree, 'event_id'));
        $this->assertMatchesRegularExpression('/\A38fbea6b36e16f37[0-9a-f]{16}\z/', $error['eventId']);
        $this->assertSame(['type' => 'mcp_tool_call', 'handled' => false], $this->vars(JsonAssert::at($tree, 'exception', 'values', 0, 'mechanism')));
    }

    #[Group('ac-06-36')]
    public function testErrorEventWithATransactionUsesItsContext(): void
    {
        $transaction = new SentryTransaction('e', 'TRACE', 'SPAN', 'OP', 42, 'NAME', new \stdClass());
        $tree = $this->tree($this->payload(tracing: true)->errorEvent(ExporterFixtures::errorEvent(), $transaction)['body']);
        $trace = JsonAssert::at($tree, 'contexts', 'trace');

        $this->assertInstanceOf(\stdClass::class, $trace);
        $this->assertSame(['trace_id', 'span_id', 'parent_span_id', 'op'], array_keys(get_object_vars($trace)));
        $this->assertSame('TRACE', JsonAssert::at($trace, 'trace_id'));
        $this->assertSame(TraceContext::spanId(ExporterFixtures::EVENT), JsonAssert::at($trace, 'span_id'));
        $this->assertSame('SPAN', JsonAssert::at($trace, 'parent_span_id'));
        $this->assertSame('OP', JsonAssert::at($trace, 'op'));
        $this->assertSame(42, JsonAssert::at($tree, 'timestamp'));
        $this->assertSame('NAME', JsonAssert::at($tree, 'transaction'));
    }

    #[Group('ac-06-37')]
    #[DataProvider('errors')]
    public function testTheExceptionComesFromTheError(mixed $error, string $type, string $value): void
    {
        $event = ExporterFixtures::event();
        $event->isError = true;
        $event->error = $error;
        $values = JsonAssert::at($this->tree($this->payload()->errorEvent($event, null)['body']), 'exception', 'values');

        $this->assertIsArray($values);
        $this->assertCount(1, $values);
        $this->assertSame($type, JsonAssert::at($values, 0, 'type'));
        $this->assertSame($value, JsonAssert::at($values, 0, 'value'));
    }

    /** @return iterable<string, array{mixed, string, string}> */
    public static function errors(): iterable
    {
        yield 'string' => ['x', 'ToolCallError', 'x'];
        yield 'null' => [null, 'ToolCallError', 'Unknown error'];
        yield 'empty string' => ['', 'ToolCallError', 'Unknown error'];
        yield 'true' => [true, 'ToolCallError', 'Unknown error'];
        yield 'number' => [7, 'ToolCallError', 'Unknown error'];
        yield 'no message' => [Json::decode('{"code":5}'), 'ToolCallError', '{"code":5}'];
        yield 'number message and boolean type' => [Json::decode('{"message":7,"type":true}'), 'true', '7'];
        yield 'null message' => [Json::decode('{"message":null}'), 'ToolCallError', 'null'];
        yield 'empty message' => [Json::decode('{"message":""}'), 'ToolCallError', ''];
        yield 'type only' => [Json::decode('{"type":"X"}'), 'X', '{"type":"X"}'];
        yield 'message and type' => [Json::decode('{"message":"m","type":"T"}'), 'T', 'm'];
        yield 'list' => [['a'], 'ToolCallError', '["a"]'];
        yield 'array with a message' => [['message' => 'm', 'type' => 'T'], 'T', 'm'];
        yield 'object message' => [Json::decode('{"message":{"a":1}}'), 'ToolCallError', '[object Object]'];
        yield 'list message' => [Json::decode('{"message":[1,null,"a"]}'), 'ToolCallError', '1,,a'];
        yield 'float message' => [Json::decode('{"message":1.5}'), 'ToolCallError', '1.5'];
    }

    #[Group('ac-06-38')]
    public function testEnvironmentAndReleaseAreTagsRightAfterSource(): void
    {
        $payload = SentryPayload::of(new SentryExporter(self::DSN, 'production', '1.2.3', true));
        $event = ExporterFixtures::errorEvent();
        $transaction = $payload->transaction($event);
        $error = $payload->errorEvent($event, $transaction);

        foreach ([$transaction->body, $error['body']] as $body) {
            $tree = $this->tree($body);
            $tags = JsonAssert::at($tree, 'tags');
            $this->assertInstanceOf(\stdClass::class, $tags);
            $this->assertSame(['source', 'environment', 'release', 'event_type'], array_slice(array_keys(get_object_vars($tags)), 0, 4));
            $this->assertSame('production', JsonAssert::at($tags, 'environment'));
            $this->assertSame('1.2.3', JsonAssert::at($tags, 'release'));
            $this->assertFalse(property_exists($body, 'environment'));
            $this->assertFalse(property_exists($body, 'release'));
        }
        $log = $payload->logEnvelope($event, 'X');
        $this->assertStringNotContainsString('production', $log);
        $this->assertStringNotContainsString('1.2.3', $log);
    }

    #[Group('ac-06-38')]
    public function testEmptyOrMissingEnvironmentAndReleaseAddNoTag(): void
    {
        foreach ([[null, null], ['', '']] as [$environment, $release]) {
            $payload = SentryPayload::of(new SentryExporter(self::DSN, $environment, $release, true));
            $tags = JsonAssert::at($this->tree($payload->transaction(ExporterFixtures::event())->body), 'tags');

            $this->assertInstanceOf(\stdClass::class, $tags);
            $this->assertSame(['source', 'event_type', 'resource', 'server_name', 'client_name', 'actor_id', 'agentcat.env', 'agentcat.Bad Key:x'], array_keys(get_object_vars($tags)));
        }
    }

    #[Group('ac-06-38')]
    public function testTagsKeepCustomerKeysUnsanitizedAndSkipBlankFields(): void
    {
        $event = new Event(id: 'evt_1', eventType: 'mcp:ping', tags: ['A b' => 'v', 5 => 'five']);
        $event->resourceName = '';
        $event->serverName = '';
        $event->clientName = '';
        $event->identifyActorGivenId = '';

        $tags = JsonAssert::at($this->tree($this->payload(tracing: true)->transaction($event)->body), 'tags');

        $this->assertInstanceOf(\stdClass::class, $tags);
        $this->assertSame(['source' => 'agentcat', 'event_type' => 'mcp:ping', 'agentcat.A b' => 'v', 'agentcat.5' => 'five'], get_object_vars($tags));
    }

    public function testATagKeyStartingWithNulIsLeftOutAndTheEnvelopeIsStillBuilt(): void
    {
        $event = new Event(id: 'evt_1', eventType: 'mcp:ping', tags: ["\0bad" => 'x', 'ok' => 'y']);
        $tags = JsonAssert::at($this->tree($this->payload(tracing: true)->transaction($event)->body), 'tags');

        $this->assertSame(['source' => 'agentcat', 'event_type' => 'mcp:ping', 'agentcat.ok' => 'y'], $this->vars($tags));
        $this->assertSame(['source' => 'agentcat', 'event_type' => 'mcp:ping', 'agentcat.ok' => 'y'], $this->vars(JsonAssert::at($this->tree($this->payload()->errorEvent($event, null)['body']), 'tags')));
    }

    public function testExtraHoldsTheTruthyFieldsAndAnyDuration(): void
    {
        $event = ExporterFixtures::errorEvent();
        $extra = JsonAssert::at($this->tree($this->payload(tracing: true)->transaction($event)->body), 'extra');

        $this->assertInstanceOf(\stdClass::class, $extra);
        $this->assertSame(['session_id', 'project_id', 'user_intent', 'actor_name', 'server_version', 'client_version', 'duration_ms', 'error'], array_keys(get_object_vars($extra)));

        $blank = new Event(id: 'evt_1', projectId: '', eventType: '', duration: 0, error: '');
        $blank->userIntent = '';
        $blank->identifyActorName = '';
        $blank->serverVersion = '';
        $blank->clientVersion = '';
        $extra = JsonAssert::at($this->tree($this->payload(tracing: true)->transaction($blank)->body), 'extra');

        $this->assertInstanceOf(\stdClass::class, $extra);
        $this->assertSame(['duration_ms' => 0], get_object_vars($extra));
    }

    #[Group('ac-06-46')]
    public function testAnEventWithOnlyAnIdAndNoTypeHasEmptyMaps(): void
    {
        $event = new Event(id: 'evt_1', eventType: '');
        $payload = $this->payload(tracing: true);
        $log = $payload->logEnvelope($event, 'X');
        $transaction = $payload->transaction($event);
        $error = $payload->errorEvent($event, null);

        $this->assertStringContainsString('"body":"MCP event","attributes":{}}', $log);
        $this->assertStringContainsString('"extra":{}', ExportJson::encode($transaction->body));
        $this->assertSame('mcp.event', $transaction->name);
        $this->assertSame('mcp.event', $transaction->op);
        $this->assertSame('{"session_id":"","event_type":""}', ExportJson::encode(JsonAssert::at($this->tree($error['body']), 'contexts', 'mcp')));
        $this->assertSame('mcp.event', JsonAssert::at($this->tree($error['body']), 'contexts', 'trace', 'op'));
        $this->assertSame('mcp.event', JsonAssert::at($this->tree($error['body']), 'transaction'));
        $this->assertStringContainsString('"extra":{}', ExportJson::encode($error['body']));
        $this->assertStringNotContainsString('agentcat":', ExportJson::encode($transaction->body));
    }

    #[Group('ac-06-46')]
    public function testEmptyPropertiesAreAnEmptyContext(): void
    {
        $event = ExporterFixtures::event();
        $event->properties = [];
        $this->assertStringContainsString('"agentcat":{}', ExportJson::encode($this->payload(tracing: true)->transaction($event)->body));

        $event->properties = null;
        $this->assertStringNotContainsString('"agentcat":{', ExportJson::encode($this->payload(tracing: true)->transaction($event)->body));
    }

    public function testTheMcpContextKeepsTheNonNullFieldsInOrder(): void
    {
        $mcp = JsonAssert::at($this->tree($this->payload()->errorEvent(ExporterFixtures::errorEvent(), null)['body']), 'contexts', 'mcp');

        $this->assertInstanceOf(\stdClass::class, $mcp);
        $this->assertSame(['resource_name', 'session_id', 'event_type', 'user_intent'], array_keys(get_object_vars($mcp)));

        $event = new Event(id: 'evt_1', eventType: 'mcp:ping');
        $mcp = JsonAssert::at($this->tree($this->payload()->errorEvent($event, null)['body']), 'contexts', 'mcp');
        $this->assertInstanceOf(\stdClass::class, $mcp);
        $this->assertSame(['session_id' => '', 'event_type' => 'mcp:ping'], get_object_vars($mcp));
    }

    public function testTheTransactionNameFollowsTheResource(): void
    {
        $event = new Event(id: 'evt_1', eventType: '');
        $event->resourceName = 'r';
        $this->assertSame('mcp - r', $this->payload(tracing: true)->transaction($event)->name);
        $this->assertSame('mcp - r', JsonAssert::at($this->tree($this->payload()->errorEvent($event, null)['body']), 'transaction'));

        $event->eventType = 'mcp:ping';
        $this->assertSame('mcp:ping - r', $this->payload(tracing: true)->transaction($event)->name);

        $event->resourceName = '';
        $this->assertSame('mcp:ping', $this->payload(tracing: true)->transaction($event)->name);
        $this->assertSame('mcp:ping', JsonAssert::at($this->tree($this->payload()->errorEvent($event, null)['body']), 'transaction'));
    }

    public function testASessionlessEventGetsRandomTraceIds(): void
    {
        $event = new Event(id: 'evt_1', eventType: 'mcp:ping');
        $payload = $this->payload(tracing: true);
        $transaction = $payload->transaction($event);
        $error = $payload->errorEvent($event, null);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $transaction->traceId);
        $this->assertSame($transaction->traceId, JsonAssert::at($this->tree($transaction->body), 'contexts', 'trace', 'trace_id'));
        $traceId = JsonAssert::at($this->tree($error['body']), 'contexts', 'trace', 'trace_id');
        $this->assertIsString($traceId);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $traceId);
        $this->assertNotSame($transaction->traceId, $traceId);
    }

    public function testTheLogLevelBodyAndIdsFollowTheEvent(): void
    {
        $payload = $this->payload();
        $event = ExporterFixtures::event();
        $log = $this->log($payload->logEnvelope($event, 'X'));

        $this->assertSame('info', JsonAssert::at($log, 'level'));
        $this->assertSame('MCP mcp:tools/call: get_weather', JsonAssert::at($log, 'body'));
        $this->assertSame(TraceContext::traceId(ExporterFixtures::SESSION), JsonAssert::at($log, 'trace_id'));
        $this->assertSame(['timestamp', 'trace_id', 'event_id', 'level', 'body', 'attributes'], array_keys(get_object_vars($log)));

        $event->isError = true;
        $this->assertSame('error', JsonAssert::at($this->log($payload->logEnvelope($event, 'X')), 'level'));
        $event->resourceName = '';
        $event->eventType = '';
        $this->assertSame('MCP event', JsonAssert::at($this->log($payload->logEnvelope($event, 'X')), 'body'));
        $event->eventType = 'mcp:tools/list';
        $this->assertSame('MCP mcp:tools/list', JsonAssert::at($this->log($payload->logEnvelope($event, 'X')), 'body'));
        $event->eventType = '';
        $event->resourceName = 'r';
        $this->assertSame('MCP event: r', JsonAssert::at($this->log($payload->logEnvelope($event, 'X')), 'body'));
    }

    public function testTheLogOfASessionlessEventHasARandomTraceId(): void
    {
        $log = $this->log($this->payload()->logEnvelope(new Event(id: 'evt_1', eventType: 'mcp:ping'), 'X'));

        $traceId = JsonAssert::at($log, 'trace_id');
        $this->assertIsString($traceId);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $traceId);
    }

    public function testBlankLogFieldsAreLeftOut(): void
    {
        $event = new Event(id: 'evt_1', eventType: '');
        $event->projectId = '';
        $event->resourceName = '';
        $event->serverName = '';
        $event->clientName = '';
        $event->userIntent = '';
        $event->identifyActorGivenId = '';
        $event->identifyActorName = '';
        $event->serverVersion = '';
        $event->clientVersion = '';

        $this->assertSame([], get_object_vars($this->attributes($event)));
    }

    public function testEachLogFieldHasItsOwnAttribute(): void
    {
        $attributes = $this->attributes(ExporterFixtures::event());

        $this->assertSame(
            ['eventType', 'resourceName', 'serverName', 'clientName', 'sessionId', 'projectId', 'duration_ms', 'actorId', 'actorName', 'userIntent', 'serverVersion', 'clientVersion', 'isError'],
            array_keys(get_object_vars($attributes)),
        );
        $expected = [
            'eventType' => 'mcp:tools/call', 'resourceName' => 'get_weather', 'serverName' => 'weather-server', 'clientName' => 'claude-desktop',
            'sessionId' => ExporterFixtures::SESSION, 'projectId' => 'proj_golden', 'actorId' => 'user_abc', 'actorName' => 'Alice',
            'userIntent' => 'Checking weather', 'serverVersion' => '1.0.0', 'clientVersion' => '2.0.0',
        ];
        foreach ($expected as $key => $value) {
            $this->assertSame($value, JsonAssert::at($attributes, $key, 'value'), $key);
            $this->assertSame('string', JsonAssert::at($attributes, $key, 'type'), $key);
        }
    }

    public function testDurationAndErrorAttributes(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = 0;
        $this->assertSame('{"value":0,"type":"double"}', ExportJson::encode(JsonAssert::at($this->attributes($event), 'duration_ms')));

        $event->duration = null;
        $event->isError = null;
        $attributes = $this->attributes($event);
        $this->assertFalse(property_exists($attributes, 'duration_ms'));
        $this->assertFalse(property_exists($attributes, 'isError'));

        $event->isError = true;
        $this->assertSame('{"value":true,"type":"boolean"}', ExportJson::encode(JsonAssert::at($this->attributes($event), 'isError')));
        $event->isError = false;
        $this->assertSame('{"value":false,"type":"boolean"}', ExportJson::encode(JsonAssert::at($this->attributes($event), 'isError')));
    }

    public function testEveryEnvelopeKeepsTheSentAtItWasGiven(): void
    {
        $payload = $this->payload(tracing: true);
        $event = ExporterFixtures::errorEvent();

        $this->assertStringContainsString('"sent_at":"S1"', $payload->logEnvelope($event, 'S1'));
        $this->assertStringContainsString('"sent_at":"S2"', $payload->transactionEnvelope($payload->transaction($event), 'S2'));
        $this->assertStringContainsString('"sent_at":"S3"', $payload->errorEnvelope($payload->errorEvent($event, null), 'S3'));
    }

    private function payload(bool $tracing = false): SentryPayload
    {
        return SentryPayload::of(new SentryExporter(self::DSN, enableTracing: $tracing));
    }

    /** @return array<mixed> */
    private function vars(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return get_object_vars($object);
    }

    private function tree(\stdClass $body): \stdClass
    {
        $tree = Json::decode(ExportJson::encode($body));
        $this->assertInstanceOf(\stdClass::class, $tree);

        return $tree;
    }

    private function log(string $envelope): \stdClass
    {
        $items = JsonAssert::at(Json::decode(explode("\n", $envelope)[2] ?? ''), 'items', 0);
        $this->assertInstanceOf(\stdClass::class, $items);

        return $items;
    }

    private function attributes(Event $event): \stdClass
    {
        $attributes = JsonAssert::at($this->log($this->payload()->logEnvelope($event, 'X')), 'attributes');
        $this->assertInstanceOf(\stdClass::class, $attributes);

        return $attributes;
    }
}
