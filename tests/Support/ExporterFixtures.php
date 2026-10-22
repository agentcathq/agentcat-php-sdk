<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Event;
use AgentCat\Internal\Json\Json;

/** The shared golden events of 06 §9.0 (golden.ts) and golden2.ts. */
final class ExporterFixtures
{
    public const string SESSION = 'ses_2yXlShd4ODDcHLYliGW9VgH3m1R';

    public const string EVENT = 'evt_2yXlTL6bElM5Whm0HjT0Iz8LL0M';

    public static function event(): Event
    {
        return new Event(
            id: self::EVENT,
            sessionId: self::SESSION,
            projectId: 'proj_golden',
            eventType: 'mcp:tools/call',
            timestamp: new \DateTimeImmutable('2025-06-15T12:00:05.123Z'),
            duration: 150,
            agentcatVersion: '2.1.1',
            serverName: 'weather-server',
            serverVersion: '1.0.0',
            clientName: 'claude-desktop',
            clientVersion: '2.0.0',
            identifyActorGivenId: 'user_abc',
            identifyActorName: 'Alice',
            identifyActorData: Json::decode('{"email":"alice@example.com"}'),
            resourceName: 'get_weather',
            parameters: Json::decode('{"city":"London"}'),
            response: Json::decode('{"content":[{"type":"text","text":"15C"}]}'),
            userIntent: 'Checking weather',
            isError: false,
            tags: ['env' => 'prod', 'Bad Key:x' => 'a,b'],
            properties: Json::decode('{"device":"desktop"}'),
        );
    }

    public static function errorEvent(): Event
    {
        $event = self::event();
        $event->isError = true;
        $event->error = Json::decode('{"message":"boom","type":"TypeError","stack":"TypeError: boom\n    at x (/a.js:1:1)"}');

        return $event;
    }

    public static function customEvent(): Event
    {
        $event = self::event();
        $event->eventType = 'agentcat:custom';
        $event->resourceName = null;
        $event->sessionId = '';
        $event->identifyActorGivenId = null;
        $event->identifyActorName = null;
        $event->identifyActorData = null;
        $event->tags = null;
        $event->properties = null;
        $event->duration = null;

        return $event;
    }

    public static function listEvent(): Event
    {
        return new Event(id: self::EVENT, sessionId: '', projectId: '', eventType: 'mcp:tools/list', timestamp: new \DateTimeImmutable('2025-06-15T12:00:05.123Z'), duration: 0, serverName: 's');
    }
}
