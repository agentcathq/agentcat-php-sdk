<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Json\Json;

/** The four golden wire events of `tests/Fixtures/golden/wire` as `Event` objects. */
final class WireEvents
{
    private const string PROJECT = 'proj_2mZ9yX8wV7uT6sR5qP4oN3mL2kJ';

    private const string SESSION = 'ses_2nH0cWzQ4m8LkP1sD7fG3hJ9kL0';

    public static function g1(): Event
    {
        return new Event(
            id: 'evt_2nH0cX1kY7d8Zq3W5r6T9u0V1aB',
            sessionId: self::SESSION,
            projectId: self::PROJECT,
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.123Z'),
            duration: 42,
            inputTokens: 6,
            outputTokens: 4,
            sdkLanguage: 'PHP',
            agentcatVersion: '0.1.0',
            serverName: 'weather-server',
            serverVersion: '1.2.0',
            clientName: 'claude-code',
            clientVersion: '2.1.220',
            identifyActorData: new \stdClass(),
            resourceName: 'get_weather',
            parameters: Json::decode('{"request":{"method":"tools/call","params":{"name":"get_weather","arguments":{"city":"Paris"}}},"extra":{}}'),
            response: Json::decode('{"content":[{"type":"text","text":"Sunny, 21C"}]}'),
            tags: ['agentcat_task_id_source' => 'minted'],
        );
    }

    public static function g2(): Event
    {
        return new Event(
            id: 'evt_2nH0cY4pQ8rS2tU6vW0xY3zA5bC',
            sessionId: self::SESSION,
            projectId: self::PROJECT,
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:01.007Z'),
            duration: 3,
            sdkLanguage: 'PHP',
            agentcatVersion: '0.1.0',
            serverName: 'weather-server',
            serverVersion: '1.2.0',
            clientName: 'claude-code',
            clientVersion: '2.1.220',
            identifyActorGivenId: 'user_123',
            identifyActorName: 'Ada Lovelace',
            identifyActorData: ['plan' => 'pro'],
            resourceName: 'ping',
            parameters: Json::decode('{"request":{"method":"tools/call","params":{"name":"ping","arguments":{}}},"extra":{}}'),
            response: Json::decode('{"content":[]}'),
            tags: ['agentcat_task_id_source' => 'minted', 'env' => 'prod'],
            properties: Json::decode('{"feature_flags":["beta_ui"],"device":{}}'),
        );
    }

    public static function g3(): Event
    {
        $golden = Golden::json('wire/g3-errored-tool-call.json');
        assert($golden instanceof \stdClass);

        return new Event(
            id: 'evt_2nH0cZ9aB1cD2eF3gH4iJ5kL6mN',
            sessionId: '',
            projectId: self::PROJECT,
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:02.500Z'),
            duration: 17,
            sdkLanguage: 'PHP',
            agentcatVersion: '0.1.0',
            identifyActorData: new \stdClass(),
            resourceName: 'get_weather',
            parameters: Json::decode('{"request":{"method":"tools/call","params":{"name":"get_weather","arguments":{"city":""}}},"extra":{}}'),
            isError: true,
            error: $golden->error,
        );
    }

    public static function g4(): Event
    {
        return new Event(
            id: 'evt_2nH0cX1kY7d8Zq3W5r6T9u0V1aB',
            sessionId: '',
            projectId: self::PROJECT,
            eventType: Constants::CUSTOM_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:05:00.000Z'),
            resourceName: 'checkout',
            parameters: ['cart_id' => 'c_42'],
            userIntent: 'Checkout completed',
            tags: ['env' => 'prod'],
            properties: ['cart_size' => 3, 'items' => ['a', 'b']],
        );
    }
}
