<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Wire;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Events\EventFields;
use AgentCat\Internal\Events\SessionInfo;
use AgentCat\Internal\Handles\HandleTags;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Internal\Wire\WireMapper;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Samples\SerializableThing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class WireMapperTest extends TestCase
{
    private function body(Event $event): \stdClass
    {
        $body = Json::decode(WireEncoder::encode($event));
        $this->assertInstanceOf(\stdClass::class, $body);

        return $body;
    }

    private function objectAt(\stdClass $body, string $key): \stdClass
    {
        $value = JsonAssert::at($body, $key);
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    private function base(): Event
    {
        return new Event(projectId: 'proj_1');
    }

    #[Group('ac-03-24')]
    #[Group('ac-07-16')]
    #[Group('ac-01-84')]
    public function testEmptySessionIdIsNull(): void
    {
        $body = $this->body(new Event(sessionId: '', projectId: 'p'));
        $this->assertTrue(property_exists($body, 'session_id'));
        $this->assertNull($body->session_id);

        $this->assertSame('ses_x', $this->body(new Event(sessionId: 'ses_x', projectId: 'p'))->session_id);
    }

    #[Group('ac-03-25')]
    #[Group('ac-07-17')]
    public function testTimestampIsUtcMilliseconds(): void
    {
        $event = new Event(projectId: 'p', timestamp: new \DateTimeImmutable('2026-10-16 14:00:00.123456', new \DateTimeZone('Europe/Paris')));
        $timestamp = $this->body($event)->timestamp;

        $this->assertSame('2026-10-16T12:00:00.123Z', $timestamp);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\z/', $timestamp);
    }

    #[Group('ac-03-27')]
    public function testStampedToolCallBody(): void
    {
        $event = new Event(
            id: 'evt_1',
            sessionId: 'ses_1',
            projectId: 'proj_1',
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            tags: ['agentcat_session_id_source' => 'minted'],
        );
        (new SessionInfo('weather-server', '1.2.0', 'claude-code', '2.1.220'))->stamp($event);
        $body = $this->body($event);

        $this->assertSame('mcp:tools/call', $body->event_type);
        $this->assertSame('PHP', $body->sdk_language);
        $this->assertSame(SdkVersion::get(), $body->agentcat_version);
        $this->assertSame('weather-server', $body->server_name);
        $this->assertSame('1.2.0', $body->server_version);
        $this->assertSame('claude-code', $body->client_name);
        $this->assertSame('2.1.220', $body->client_version);
        $this->assertEquals((object) ['agentcat_session_id_source' => 'minted'], $body->tags);
        $this->assertFalse(property_exists($body, 'ip_address'));
        $this->assertFalse(property_exists($body, 'event_id'));
    }

    #[Group('ac-03-28')]
    #[Group('ac-07-18')]
    #[Group('ac-01-73')]
    public function testCustomEventType(): void
    {
        $this->assertSame('agentcat:custom', $this->body(new Event(projectId: 'p', eventType: Constants::CUSTOM_EVENT_TYPE))->event_type);
    }

    #[Group('ac-03-29')]
    #[Group('ac-07-22')]
    #[Group('ac-07-26')]
    public function testEmptyFreeFormFieldsAreObjects(): void
    {
        $body = $this->body(new Event(
            projectId: 'p',
            identifyActorData: [],
            parameters: [],
            response: [],
            error: [],
            tags: [],
            properties: [],
        ));

        foreach (['parameters', 'response', 'error', 'identify_data', 'tags', 'properties'] as $key) {
            $this->assertEquals(new \stdClass(), $body->{$key}, $key);
        }
    }

    #[Group('ac-03-30')]
    public function testIsError(): void
    {
        $this->assertFalse(property_exists($this->body($this->base()), 'is_error'));
        $this->assertTrue($this->body(new Event(projectId: 'p', isError: true))->is_error);
    }

    #[Group('ac-03-31')]
    public function testInputTokens(): void
    {
        $this->assertFalse(property_exists($this->body($this->base()), 'input_tokens'));
        $this->assertSame(6, $this->body(new Event(projectId: 'p', inputTokens: 6))->input_tokens);
    }

    #[Group('ac-03-34')]
    #[Group('ac-07-29')]
    public function testInvalidUtf8InResponseIsScrubbed(): void
    {
        $item = new \stdClass();
        $item->type = 'text';
        $item->text = "bad \xB1 byte";
        $response = (object) ['content' => [$item]];

        $body = $this->body(new Event(projectId: 'p', response: $response));

        $this->assertSame("bad \u{FFFD} byte", JsonAssert::at($body, 'response', 'content', 0, 'text'));
    }

    #[Group('ac-07-14')]
    public function testRequiredKeys(): void
    {
        $body = $this->body(new Event(id: 'evt_1', projectId: 'proj_1', eventType: 'mcp:tools/call'));

        $this->assertSame('evt_1', $body->id);
        $this->assertSame('proj_1', $body->project_id);
        $this->assertNotSame('', $body->event_type);
        $this->assertNotSame('', $body->timestamp);
    }

    #[Group('ac-07-20')]
    public function testOnlyKnownKeysAndNoNulls(): void
    {
        $full = new Event(
            id: 'i',
            sessionId: 's',
            projectId: 'p',
            eventType: 't',
            duration: 1,
            inputTokens: 1,
            outputTokens: 1,
            ipAddress: '1.1.1.1',
            sdkLanguage: 'PHP',
            agentcatVersion: 'v',
            serverName: 'n',
            serverVersion: 'v',
            clientName: 'n',
            clientVersion: 'v',
            identifyActorGivenId: 'g',
            identifyActorName: 'n',
            identifyActorData: [],
            resourceName: 'r',
            parameters: [],
            response: [],
            userIntent: 'u',
            isError: false,
            error: [],
            tags: [],
            properties: [],
            actorId: 'a',
        );
        $allowed = ['id', 'project_id', 'session_id', 'actor_id', 'event_id', 'event_type', 'is_error', 'error', 'resource_name', 'duration', 'input_tokens', 'output_tokens', 'timestamp', 'user_intent', 'parameters', 'response', 'identify_actor_given_id', 'identify_actor_name', 'identify_data', 'tags', 'properties', 'ip_address', 'sdk_language', 'agentcat_version', 'server_name', 'server_version', 'client_name', 'client_version'];

        $keys = array_keys(get_object_vars($this->body($full)));
        $this->assertSame([], array_diff($keys, $allowed));
        $this->assertCount(27, $keys);

        $bare = get_object_vars($this->body($this->base()));
        $this->assertSame(['session_id'], array_keys(array_filter($bare, is_null(...))));
    }

    #[Group('ac-07-21')]
    public function testTagOrderIsCustomerFirst(): void
    {
        $tags = HandleTags::merge(['env' => 'x'], ['agentcat_session_id_source' => 'minted']);

        $this->assertSame(['env', 'agentcat_session_id_source'], array_keys(get_object_vars($this->objectAt($this->body(new Event(projectId: 'p', tags: $tags)), 'tags'))));
    }

    #[Group('ac-07-23')]
    public function testNumericTagKeysStayKeys(): void
    {
        $body = json_encode(WireMapper::toRequest(new Event(projectId: 'p', tags: ['0' => 'x', '1' => 'y'])), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"tags":{"0":"x","1":"y"}', $body);
    }

    #[Group('ac-07-24')]
    #[Group('ac-03-20')]
    #[Group('ac-01-85')]
    public function testActorFields(): void
    {
        $this->assertEquals(new \stdClass(), $this->body(new Event(projectId: 'p', identifyActorData: new \stdClass()))->identify_data);
        $this->assertEquals((object) ['plan' => 'pro'], $this->body(new Event(projectId: 'p', identifyActorData: ['plan' => 'pro']))->identify_data);
        $this->assertSame('u1', $this->body(new Event(projectId: 'p', identifyActorGivenId: 'u1'))->actor_id);
        $this->assertSame('legacy', $this->body(new Event(projectId: 'p', identifyActorGivenId: 'u1', actorId: 'legacy'))->actor_id);
        $this->assertSame('u1', $this->body(new Event(projectId: 'p', identifyActorGivenId: 'u1', actorId: ''))->actor_id);
        $this->assertFalse(property_exists($this->body($this->base()), 'actor_id'));
        $this->assertSame('only', $this->body(new Event(projectId: 'p', actorId: 'only'))->actor_id);
    }

    #[Group('ac-07-25')]
    public function testEmptyNestedArgumentsStayObjects(): void
    {
        $parameters = Json::decode('{"request":{"params":{"arguments":{}}}}');

        $this->assertEquals(new \stdClass(), JsonAssert::at($this->body(new Event(projectId: 'p', parameters: $parameters)), 'parameters', 'request', 'params', 'arguments'));
    }

    #[Group('ac-07-27')]
    public function testJsonSerializableResponse(): void
    {
        $this->assertEquals((object) ['kind' => 'thing', 'size' => 2], $this->body(new Event(projectId: 'p', response: new SerializableThing()))->response);
    }

    #[Group('ac-07-28')]
    public function testNonFinitePropertiesBecomeNull(): void
    {
        $body = json_encode(WireMapper::toRequest(new Event(projectId: 'p', properties: ['ratio' => NAN, 'n' => INF, 'ok' => 1.5])), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"properties":{"ratio":null,"n":null,"ok":1.5}', $body);
    }

    #[Group('ac-07-30')]
    public function testDurationIsAnInteger(): void
    {
        $this->assertSame(13, $this->body(new Event(projectId: 'p', duration: 12.7))->duration);
        $this->assertSame(12, $this->body(new Event(projectId: 'p', duration: 12.2))->duration);
        $this->assertSame(42, $this->body(new Event(projectId: 'p', duration: 42))->duration);
        $this->assertFalse(property_exists($this->body(new Event(projectId: 'p', duration: NAN)), 'duration'));
        $this->assertFalse(property_exists($this->body(new Event(projectId: 'p', duration: INF)), 'duration'));
        $this->assertFalse(property_exists($this->body($this->base()), 'duration'));
    }

    #[Group('ac-07-31')]
    public function testMapperNeverShortensStrings(): void
    {
        $this->assertSame(str_repeat('n', 300), $this->body(new Event(projectId: 'p', serverName: str_repeat('n', 300)))->server_name);
    }

    #[Group('ac-07-32')]
    public function testIpAddressIsSentAsGiven(): void
    {
        $this->assertFalse(property_exists($this->body($this->base()), 'ip_address'));
        $this->assertSame('not-an-ip', $this->body(new Event(projectId: 'p', ipAddress: 'not-an-ip'))->ip_address);
    }

    public function testMissingProjectIdBecomesEmptyString(): void
    {
        $this->assertSame('', $this->body(new Event())->project_id);
    }

    public function testNegativeEncodings(): void
    {
        $this->assertStringContainsString('"tags":{}', json_encode(WireMapper::toRequest(new Event(projectId: 'p', tags: [])), JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('"identify_data":{}', json_encode(WireMapper::toRequest(new Event(projectId: 'p', identifyActorData: [])), JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('"tags":{"0":"x"}', json_encode(WireMapper::toRequest(new Event(projectId: 'p', tags: ['0' => 'x'])), JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('"properties":{"x":null}', json_encode(WireMapper::toRequest(new Event(projectId: 'p', properties: ['x' => NAN])), JSON_THROW_ON_ERROR));
    }

    public function testScalarFreeFormFieldsAreLeftOut(): void
    {
        $event = new Event(projectId: 'p', response: 'ok', error: 'boom');
        $body = $this->body($event);

        $this->assertFalse(property_exists($body, 'response'));
        $this->assertFalse(property_exists($body, 'error'));
        $this->assertSame(['error', 'response'], WireMapper::droppedFields($event));
    }

    public function testDroppedFieldsFollowFieldOrderAndUseWireKeys(): void
    {
        $event = new Event(projectId: 'p', identifyActorData: 5, parameters: static fn(): int => 1, properties: 'x');

        $this->assertSame(['parameters', 'identify_data', 'properties'], WireMapper::droppedFields($event));
        $this->assertSame([], WireMapper::droppedFields($this->base()));
        $this->assertSame([], WireMapper::droppedFields(new Event(parameters: [], response: new \stdClass())));
    }

    #[Group('ac-07-24')]
    #[DataProvider('freeFormFields')]
    public function testScalarOfEveryKindIsDropped(string $field): void
    {
        $event = new Event();
        EventFields::assign($event, [$field => 7]);

        $this->assertCount(1, WireMapper::droppedFields($event));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function freeFormFields(): iterable
    {
        foreach (['error', 'parameters', 'response', 'identifyActorData', 'properties'] as $field) {
            yield $field => [$field];
        }
    }

    public function testNonFreeFormObjectsBecomeObjects(): void
    {
        $this->assertEquals((object) ['kind' => 'thing', 'size' => 2], $this->body(new Event(projectId: 'p', properties: new SerializableThing()))->properties);
    }

    public function testNonListArrayWithNumericKeys(): void
    {
        $body = json_encode(WireMapper::toRequest(new Event(projectId: 'p', properties: [1 => 'x', 2 => 'y'])), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"properties":{"1":"x","2":"y"}', $body);
    }

    public function testListAsFreeFormBecomesIndexedObject(): void
    {
        $body = json_encode(WireMapper::toRequest(new Event(projectId: 'p', properties: ['a', 'b'])), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"properties":{"0":"a","1":"b"}', $body);
    }

    public function testNestedEmptyStdClassStaysObject(): void
    {
        $body = json_encode(WireMapper::toRequest(new Event(projectId: 'p', properties: ['device' => new \stdClass()])), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"properties":{"device":{}}', $body);
    }
}
