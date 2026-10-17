<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry\PostHog;

use AgentCat\Event;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Internal\Id\UuidV7;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Telemetry\ExportJson;
use AgentCat\Internal\Telemetry\PostHog\PostHogPayload;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PostHogPayloadTest extends TestCase
{
    #[Group('ac-06-43')]
    #[DataProvider('eventNames')]
    public function testEventTypesMapToPostHogNames(string $type, string $name): void
    {
        $this->assertSame($name, PostHogPayload::eventName($type));
    }

    /** @return iterable<string, array{string, string}> */
    public static function eventNames(): iterable
    {
        yield 'tools/call' => ['mcp:tools/call', 'mcp_tool_call'];
        yield 'tools/list' => ['mcp:tools/list', 'mcp_tools_list'];
        yield 'initialize' => ['mcp:initialize', 'mcp_initialize'];
        yield 'resources/read' => ['mcp:resources/read', 'mcp_resource_read'];
        yield 'resources/list' => ['mcp:resources/list', 'mcp_resources_list'];
        yield 'prompts/get' => ['mcp:prompts/get', 'mcp_prompt_get'];
        yield 'prompts/list' => ['mcp:prompts/list', 'mcp_prompts_list'];
        yield 'ping' => ['mcp:ping', 'mcp_ping'];
        yield 'templates' => ['mcp:resources/templates/list', 'mcp_resources_templates_list'];
        yield 'custom mcp type' => ['mcp:custom/type', 'mcp_custom_type'];
        yield 'custom agentcat type' => ['agentcat:custom', 'mcp_agentcat:custom'];
        yield 'identify' => ['agentcat:identify', 'mcp_agentcat:identify'];
        yield 'empty' => ['', 'mcp_'];
        yield 'only the first prefix goes' => ['mcp:mcp:x', 'mcp_mcp:x'];
        yield 'prefix in the middle stays' => ['x/mcp:y', 'mcp_x_mcp:y'];
    }

    #[Group('ac-06-44')]
    public function testTagsReplaceAiSpanDefaults(): void
    {
        $event = ExporterFixtures::event();
        $event->tags = ['$ai_trace_id' => 'x', '$ai_span_name' => 'y'];
        $span = $this->properties($event, 1, ai: true);

        $this->assertSame('x', JsonAssert::at($span, '$ai_trace_id'));
        $this->assertSame('y', JsonAssert::at($span, '$ai_span_name'));
        $this->assertSame(['$ai_trace_id', '$ai_span_id', '$ai_span_name'], array_slice(array_keys(get_object_vars($span)), 0, 3));
    }

    #[Group('ac-06-44')]
    public function testPropertiesReplaceTagsAndEverythingElse(): void
    {
        $event = ExporterFixtures::event();
        $event->tags = ['a' => 'tag', 'source' => 'tag'];
        $event->properties = Json::decode('{"a":"prop","server_name":"mine"}');

        $capture = $this->properties($event, 0);
        $span = $this->properties($event, 1, ai: true);

        foreach ([$capture, $span] as $properties) {
            $this->assertSame('prop', JsonAssert::at($properties, 'a'));
            $this->assertSame('tag', JsonAssert::at($properties, 'source'));
            $this->assertSame('mine', JsonAssert::at($properties, 'server_name'));
        }
        $this->assertSame('source', array_key_first(get_object_vars($capture)));
    }

    #[Group('ac-06-44')]
    public function testIntegerKeysAndAnObjectOfPropertiesAreSpread(): void
    {
        $event = new Event(id: 'evt_1', eventType: 'mcp:ping', tags: [7 => 'seven'], properties: (object) ['p' => 1]);
        $properties = $this->properties($event, 0);

        $this->assertSame('seven', JsonAssert::at($properties, '7'));
        $this->assertSame(1, JsonAssert::at($properties, 'p'));
    }

    #[Group('ac-06-44')]
    public function testScalarOrFalsyPropertiesAndTagsAreNotSpread(): void
    {
        foreach (['text', 5, true, [], 0, ''] as $properties) {
            $event = new Event(id: 'evt_1', eventType: 'mcp:ping', tags: [], properties: $properties);

            $this->assertSame(['source'], array_keys(get_object_vars($this->properties($event, 0))));
        }
    }

    #[Group('ac-06-45')]
    public function testActorDataReplacesTheActorNameInSet(): void
    {
        $event = ExporterFixtures::event();
        $event->identifyActorData = Json::decode('{"name":"Bob"}');
        $event->identifyActorName = 'Alice';
        $set = JsonAssert::at($this->properties($event, 0), '$set');

        $this->assertSame(['name' => 'Bob'], get_object_vars($set instanceof \stdClass ? $set : new \stdClass()));
    }

    #[Group('ac-06-45')]
    public function testTheActorNameAndDataFillSet(): void
    {
        $set = JsonAssert::at($this->properties(ExporterFixtures::event(), 0), '$set');

        $this->assertSame('{"name":"Alice","email":"alice@example.com"}', ExportJson::encode($set));
    }

    #[Group('ac-06-45')]
    #[DataProvider('noIdentity')]
    public function testNoIdentityMeansNoSet(?string $name, mixed $data): void
    {
        $event = new Event(id: 'evt_1', eventType: 'mcp:ping', identifyActorName: $name, identifyActorData: $data);

        $this->assertFalse(property_exists($this->properties($event, 0), '$set'));
    }

    /** @return iterable<string, array{?string, mixed}> */
    public static function noIdentity(): iterable
    {
        yield 'nothing' => [null, null];
        yield 'empty data' => [null, []];
        yield 'empty name and data' => ['', []];
        yield 'empty object' => [null, new \stdClass()];
        yield 'scalar data' => [null, 'text'];
        yield 'false data' => [null, false];
    }

    #[Group('ac-06-45')]
    public function testTheActorNameAloneAndDataAloneFillSet(): void
    {
        $name = new Event(id: 'evt_1', eventType: 'mcp:ping', identifyActorName: 'Alice');
        $data = new Event(id: 'evt_1', eventType: 'mcp:ping', identifyActorData: ['plan' => 'pro']);

        $this->assertSame('{"name":"Alice"}', ExportJson::encode(JsonAssert::at($this->properties($name, 0), '$set')));
        $this->assertSame('{"plan":"pro"}', ExportJson::encode(JsonAssert::at($this->properties($data, 0), '$set')));
    }

    public function testTheDistinctIdIsTheActorThenTheSessionThenAnonymous(): void
    {
        $event = ExporterFixtures::event();
        $this->assertSame('user_abc', $this->distinct($event));

        $event->identifyActorGivenId = null;
        $this->assertSame(ExporterFixtures::SESSION, $this->distinct($event));

        $event->identifyActorGivenId = '';
        $this->assertSame($event->sessionId, $this->distinct($event));

        $event->sessionId = '';
        $this->assertSame('anonymous', $this->distinct($event));
    }

    public function testEveryItemOfABatchHasTheSameDistinctIdTimestampAndType(): void
    {
        $event = ExporterFixtures::errorEvent();
        $items = $this->items($event, ai: true);

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame('user_abc', JsonAssert::at($item, 'distinct_id'));
            $this->assertSame('2025-06-15T12:00:05.123Z', JsonAssert::at($item, 'timestamp'));
            $this->assertSame('capture', JsonAssert::at($item, 'type'));
            $this->assertSame(['event', 'distinct_id', 'properties', 'timestamp', 'type'], array_keys(get_object_vars($item)));
        }
    }

    public function testASessionlessErrorWithAiTracingCarriesNoSessionIds(): void
    {
        $event = ExporterFixtures::errorEvent();
        $event->sessionId = '';
        $items = $this->items($event, ai: true);

        $this->assertCount(3, $items);
        $this->assertSame(['mcp_tool_call', '$exception', '$ai_span'], array_map(static fn(\stdClass $item): mixed => $item->event, $items));
        foreach (array_keys($items) as $index) {
            $this->assertFalse(property_exists($this->properties($event, $index, ai: true), '$session_id'));
        }
        $span = $this->properties($event, 2, ai: true);
        $this->assertFalse(property_exists($span, '$ai_session_id'));
        $this->assertSame(UuidV7::fromPrefixedId($event->id), JsonAssert::at($span, '$ai_trace_id'));
    }

    public function testASessionHasATraceAndSessionIds(): void
    {
        $event = ExporterFixtures::errorEvent();
        $uuid = UuidV7::fromPrefixedId(ExporterFixtures::SESSION);
        $span = $this->properties($event, 2, ai: true);

        $this->assertSame($uuid, JsonAssert::at($span, '$ai_trace_id'));
        $this->assertSame($uuid, JsonAssert::at($span, '$session_id'));
        $this->assertSame('agentcat_' . ExporterFixtures::SESSION, JsonAssert::at($span, '$ai_session_id'));
        $this->assertSame(UuidV7::fromPrefixedId(ExporterFixtures::EVENT), JsonAssert::at($span, '$ai_span_id'));
        $this->assertSame($uuid, JsonAssert::at($this->properties($event, 0, ai: true), '$session_id'));
        $this->assertSame($uuid, JsonAssert::at($this->properties($event, 1, ai: true), '$session_id'));
    }

    public function testAnExceptionNeedsAnErrorFlagAndAnError(): void
    {
        foreach ([[true, null], [true, ''], [false, 'boom'], [null, 'boom'], [false, null], [true, 0], [true, false]] as [$flag, $error]) {
            $event = ExporterFixtures::event();
            $event->isError = $flag;
            $event->error = $error;

            $this->assertCount(1, $this->items($event), var_export([$flag, $error], true));
        }
        $event = ExporterFixtures::event();
        $event->isError = true;
        $event->error = 'boom';
        $this->assertCount(2, $this->items($event));
    }

    public function testAStringErrorHasNoExceptionDetails(): void
    {
        $event = ExporterFixtures::event();
        $event->isError = true;
        $event->error = 'boom';
        $exception = $this->properties($event, 1);

        $this->assertSame(['$exception_source', '$session_id', 'resource_name', 'tool_name', 'server_name', 'server_version', 'client_name', 'client_version'], array_keys(get_object_vars($exception)));
        $this->assertSame('backend', JsonAssert::at($exception, '$exception_source'));
    }

    public function testAnObjectErrorGivesMessageTypeAndStackWhenTruthy(): void
    {
        $event = ExporterFixtures::errorEvent();
        $exception = $this->properties($event, 1);

        $this->assertSame(
            ['$exception_source', '$session_id', '$exception_message', '$exception_type', '$exception_stacktrace', 'resource_name', 'tool_name', 'server_name', 'server_version', 'client_name', 'client_version'],
            array_keys(get_object_vars($exception)),
        );
        $this->assertSame('boom', JsonAssert::at($exception, '$exception_message'));
        $this->assertSame('TypeError', JsonAssert::at($exception, '$exception_type'));
        $this->assertSame("TypeError: boom\n    at x (/a.js:1:1)", JsonAssert::at($exception, '$exception_stacktrace'));

        $event->error = Json::decode('{"message":"","type":0,"stack":null}');
        $this->assertSame(['$exception_source', '$session_id'], array_slice(array_keys(get_object_vars($this->properties($event, 1))), 0, 2));
        $this->assertFalse(property_exists($this->properties($event, 1), '$exception_message'));
        $this->assertFalse(property_exists($this->properties($event, 1), '$exception_type'));
        $this->assertFalse(property_exists($this->properties($event, 1), '$exception_stacktrace'));

        $event->error = ['message' => 'from array'];
        $this->assertSame('from array', JsonAssert::at($this->properties($event, 1), '$exception_message'));
    }

    public function testTheExceptionOfANonToolCallHasNoToolName(): void
    {
        $event = ExporterFixtures::errorEvent();
        $event->eventType = 'mcp:resources/read';
        $event->sessionId = '';
        $event->serverName = '';
        $event->serverVersion = null;
        $event->clientName = '';
        $event->clientVersion = null;
        $event->resourceName = '';
        $exception = $this->properties($event, 1);

        $this->assertSame(['$exception_source', '$exception_message', '$exception_type', '$exception_stacktrace'], array_keys(get_object_vars($exception)));

        $event->resourceName = 'file:///a';
        $this->assertSame('file:///a', JsonAssert::at($this->properties($event, 1), 'resource_name'));
        $this->assertFalse(property_exists($this->properties($event, 1), 'tool_name'));
    }

    public function testNoSpanWithoutAiTracingOrForOtherEventTypes(): void
    {
        $this->assertCount(1, $this->items(ExporterFixtures::event()));
        $this->assertCount(2, $this->items(ExporterFixtures::event(), ai: true));

        $event = ExporterFixtures::event();
        $event->eventType = 'mcp:tools/list';
        $this->assertCount(1, $this->items($event, ai: true));
    }

    public function testToolNameOnlyForToolCalls(): void
    {
        $event = ExporterFixtures::event();
        $properties = $this->properties($event, 0);
        $this->assertSame('get_weather', JsonAssert::at($properties, 'tool_name'));
        $this->assertSame(['resource_name', 'tool_name'], array_slice(array_keys(get_object_vars($properties)), 2, 2));

        $event->eventType = 'mcp:tools/list';
        $properties = $this->properties($event, 0);
        $this->assertSame('get_weather', JsonAssert::at($properties, 'resource_name'));
        $this->assertFalse(property_exists($properties, 'tool_name'));

        $event->resourceName = '';
        $properties = $this->properties($event, 0);
        $this->assertFalse(property_exists($properties, 'resource_name'));
        $this->assertFalse(property_exists($properties, 'tool_name'));
    }

    public function testBlankCaptureFieldsAreLeftOut(): void
    {
        $event = new Event(id: 'evt_1', projectId: '', eventType: 'mcp:ping', serverName: '', serverVersion: '', clientName: '', clientVersion: '', userIntent: '');

        $this->assertSame(['source'], array_keys(get_object_vars($this->properties($event, 0))));
    }

    public function testTheCaptureKeepsItsOrderAndZeroValues(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = 0;
        $event->isError = false;
        $event->parameters = 0;
        $event->response = '';
        $properties = $this->properties($event, 0);

        $this->assertSame(
            ['source', '$session_id', 'resource_name', 'tool_name', 'duration_ms', 'server_name', 'server_version', 'client_name', 'client_version', 'project_id', 'user_intent', 'is_error', 'parameters', 'response', '$set', 'env', 'Bad Key:x', 'device'],
            array_keys(get_object_vars($properties)),
        );
        $this->assertSame(0, JsonAssert::at($properties, 'duration_ms'));
        $this->assertFalse(JsonAssert::at($properties, 'is_error'));
        $this->assertSame(0, JsonAssert::at($properties, 'parameters'));
        $this->assertSame('', JsonAssert::at($properties, 'response'));
    }

    public function testMissingValuesAreLeftOutOfTheCapture(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = null;
        $event->isError = null;
        $event->parameters = null;
        $event->response = null;
        $properties = $this->properties($event, 0);

        foreach (['duration_ms', 'is_error', 'parameters', 'response'] as $key) {
            $this->assertFalse(property_exists($properties, $key), $key);
        }
    }

    public function testParametersAndResponseStringsPassThroughAsStrings(): void
    {
        $event = ExporterFixtures::event();
        $event->parameters = '{"city":"London"}';
        $event->response = 'text';
        $capture = $this->properties($event, 0, ai: true);
        $span = $this->properties($event, 1, ai: true);

        $this->assertSame('{"city":"London"}', JsonAssert::at($capture, 'parameters'));
        $this->assertSame('text', JsonAssert::at($capture, 'response'));
        $this->assertSame('{"city":"London"}', JsonAssert::at($span, '$ai_input_state'));
        $this->assertSame('text', JsonAssert::at($span, '$ai_output_state'));
    }

    public function testTheAiLatencyIsSeconds(): void
    {
        $event = ExporterFixtures::event();
        $event->duration = 1000;
        $this->assertStringContainsString('"$ai_latency":1,', ExportJson::encode($this->properties($event, 1, ai: true)));
        $event->duration = 1000.0;
        $this->assertStringContainsString('"$ai_latency":1,', ExportJson::encode($this->properties($event, 1, ai: true)));
        $event->duration = 150;
        $this->assertStringContainsString('"$ai_latency":0.15,', ExportJson::encode($this->properties($event, 1, ai: true)));
        $event->duration = 0;
        $this->assertStringContainsString('"$ai_latency":0,', ExportJson::encode($this->properties($event, 1, ai: true)));
        $event->duration = null;
        $this->assertFalse(property_exists($this->properties($event, 1, ai: true), '$ai_latency'));
    }

    public function testTheSpanFollowsTheEvent(): void
    {
        $event = ExporterFixtures::event();
        $span = $this->properties($event, 1, ai: true);

        $this->assertSame(
            ['$ai_trace_id', '$ai_span_id', '$ai_span_name', '$ai_is_error', 'source', '$ai_session_id', '$session_id', '$ai_latency', '$ai_input_state', '$ai_output_state', 'server_name', 'client_name', 'env', 'Bad Key:x', 'device'],
            array_keys(get_object_vars($span)),
        );
        $this->assertSame('get_weather', JsonAssert::at($span, '$ai_span_name'));
        $this->assertFalse(JsonAssert::at($span, '$ai_is_error'));
        $this->assertSame('agentcat', JsonAssert::at($span, 'source'));

        $event->resourceName = '';
        $event->isError = null;
        $event->parameters = null;
        $event->response = null;
        $event->serverName = '';
        $event->clientName = null;
        $span = $this->properties($event, 1, ai: true);
        $this->assertSame('unknown_tool', JsonAssert::at($span, '$ai_span_name'));
        $this->assertFalse(JsonAssert::at($span, '$ai_is_error'));
        foreach (['$ai_input_state', '$ai_output_state', 'server_name', 'client_name', '$ai_error'] as $key) {
            $this->assertFalse(property_exists($span, $key), $key);
        }
    }

    public function testTheSpanCarriesTheErrorOfAFailedCallOnly(): void
    {
        $failed = ExporterFixtures::errorEvent();
        $span = $this->properties($failed, 2, ai: true);
        $this->assertTrue(JsonAssert::at($span, '$ai_is_error'));
        $this->assertSame('boom', JsonAssert::at($span, '$ai_error', 'message'));

        $failed->error = 'text';
        $this->assertSame('text', JsonAssert::at($this->properties($failed, 2, ai: true), '$ai_error'));

        $flagOnly = ExporterFixtures::event();
        $flagOnly->isError = true;
        $this->assertTrue(JsonAssert::at($this->properties($flagOnly, 1, ai: true), '$ai_is_error'));
        $this->assertFalse(property_exists($this->properties($flagOnly, 1, ai: true), '$ai_error'));

        $errorOnly = ExporterFixtures::event();
        $errorOnly->error = 'text';
        $this->assertFalse(property_exists($this->properties($errorOnly, 1, ai: true), '$ai_error'));
    }

    #[Group('ac-06-42')]
    public function testHostsBuildTheBatchUrl(): void
    {
        $this->assertSame('https://us.i.posthog.com/batch', PostHogPayload::of(new PostHogExporter('k'))->batchUrl);
        $this->assertSame('https://us.i.posthog.com/batch', PostHogPayload::of(new PostHogExporter('k', ''))->batchUrl);
        $this->assertSame('https://eu.i.posthog.com/batch', PostHogPayload::of(new PostHogExporter('k', 'https://eu.i.posthog.com/'))->batchUrl);
        $this->assertSame('https://eu.i.posthog.com/batch', PostHogPayload::of(new PostHogExporter('k', 'https://eu.i.posthog.com'))->batchUrl);
        $this->assertSame('https://h//batch', PostHogPayload::of(new PostHogExporter('k', 'https://h//'))->batchUrl);
        $this->assertSame('http://127.0.0.1:9/ph/batch', PostHogPayload::of(new PostHogExporter('k', 'http://127.0.0.1:9/ph/'))->batchUrl);
        $this->assertSame('0/batch', PostHogPayload::of(new PostHogExporter('k', '0'))->batchUrl);
    }

    public function testTheRequestPostsTheKeyAndTheBatchAsJson(): void
    {
        $payload = PostHogPayload::of(new PostHogExporter('phc_x', 'https://h'));
        $batch = $payload->batch(ExporterFixtures::event());
        $request = $payload->request($batch);

        $this->assertSame('https://h/batch', $request->url);
        $this->assertSame(['Content-Type' => 'application/json'], $request->headers);
        $this->assertSame(ExportJson::encode(['api_key' => 'phc_x', 'batch' => $batch]), $request->body);
        $this->assertStringStartsWith('{"api_key":"phc_x","batch":[{"event":"mcp_tool_call"', $request->body);
    }

    /** @return list<\stdClass> */
    private function items(Event $event, bool $ai = false): array
    {
        $items = Json::decode(ExportJson::encode(PostHogPayload::of(new PostHogExporter('phc_x', enableAITracing: $ai))->batch($event)));
        $this->assertIsArray($items);
        $list = [];
        foreach ($items as $item) {
            $this->assertInstanceOf(\stdClass::class, $item);
            $list[] = $item;
        }

        return $list;
    }

    private function properties(Event $event, int $index, bool $ai = false): \stdClass
    {
        $properties = JsonAssert::at($this->items($event, $ai)[$index] ?? null, 'properties');
        $this->assertInstanceOf(\stdClass::class, $properties);

        return $properties;
    }

    private function distinct(Event $event): string
    {
        $id = JsonAssert::at($this->items($event)[0] ?? null, 'distinct_id');
        $this->assertIsString($id);

        return $id;
    }
}
