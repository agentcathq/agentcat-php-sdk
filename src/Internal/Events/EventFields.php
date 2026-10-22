<?php

declare(strict_types=1);

namespace AgentCat\Internal\Events;

use AgentCat\Event;

/**
 * The only place that reads and writes `Event` fields by name.
 *
 * @internal
 */
final class EventFields
{
    /**
     * @return array<string, mixed> every field, in declaration order
     */
    public static function toArray(Event $event): array
    {
        return [
            'id' => $event->id,
            'sessionId' => $event->sessionId,
            'projectId' => $event->projectId,
            'eventType' => $event->eventType,
            'timestamp' => $event->timestamp,
            'duration' => $event->duration,
            'inputTokens' => $event->inputTokens,
            'outputTokens' => $event->outputTokens,
            'ipAddress' => $event->ipAddress,
            'sdkLanguage' => $event->sdkLanguage,
            'agentcatVersion' => $event->agentcatVersion,
            'serverName' => $event->serverName,
            'serverVersion' => $event->serverVersion,
            'clientName' => $event->clientName,
            'clientVersion' => $event->clientVersion,
            'identifyActorGivenId' => $event->identifyActorGivenId,
            'identifyActorName' => $event->identifyActorName,
            'identifyActorData' => $event->identifyActorData,
            'resourceName' => $event->resourceName,
            'parameters' => $event->parameters,
            'response' => $event->response,
            'userIntent' => $event->userIntent,
            'isError' => $event->isError,
            'error' => $event->error,
            'tags' => $event->tags,
            'properties' => $event->properties,
            'actorId' => $event->actorId,
        ];
    }

    /**
     * Writes the named fields; unknown names are ignored.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \TypeError when a value does not fit the field type
     */
    public static function assign(Event $event, array $fields): void
    {
        foreach ($fields as $name => $value) {
            switch ($name) {
                case 'id':
                    $event->id = self::string($name, $value);
                    break;
                case 'sessionId':
                    $event->sessionId = self::string($name, $value);
                    break;
                case 'projectId':
                    $event->projectId = self::nullableString($name, $value);
                    break;
                case 'eventType':
                    $event->eventType = self::string($name, $value);
                    break;
                case 'timestamp':
                    $event->timestamp = self::timestamp($name, $value);
                    break;
                case 'duration':
                    $event->duration = self::number($name, $value);
                    break;
                case 'inputTokens':
                    $event->inputTokens = self::nullableInt($name, $value);
                    break;
                case 'outputTokens':
                    $event->outputTokens = self::nullableInt($name, $value);
                    break;
                case 'ipAddress':
                    $event->ipAddress = self::nullableString($name, $value);
                    break;
                case 'sdkLanguage':
                    $event->sdkLanguage = self::nullableString($name, $value);
                    break;
                case 'agentcatVersion':
                    $event->agentcatVersion = self::nullableString($name, $value);
                    break;
                case 'serverName':
                    $event->serverName = self::nullableString($name, $value);
                    break;
                case 'serverVersion':
                    $event->serverVersion = self::nullableString($name, $value);
                    break;
                case 'clientName':
                    $event->clientName = self::nullableString($name, $value);
                    break;
                case 'clientVersion':
                    $event->clientVersion = self::nullableString($name, $value);
                    break;
                case 'identifyActorGivenId':
                    $event->identifyActorGivenId = self::nullableString($name, $value);
                    break;
                case 'identifyActorName':
                    $event->identifyActorName = self::nullableString($name, $value);
                    break;
                case 'identifyActorData':
                    $event->identifyActorData = $value;
                    break;
                case 'resourceName':
                    $event->resourceName = self::nullableString($name, $value);
                    break;
                case 'parameters':
                    $event->parameters = $value;
                    break;
                case 'response':
                    $event->response = $value;
                    break;
                case 'userIntent':
                    $event->userIntent = self::nullableString($name, $value);
                    break;
                case 'isError':
                    $event->isError = self::nullableBool($name, $value);
                    break;
                case 'error':
                    $event->error = $value;
                    break;
                case 'tags':
                    $event->tags = self::tags($name, $value);
                    break;
                case 'properties':
                    $event->properties = $value;
                    break;
                case 'actorId':
                    $event->actorId = self::nullableString($name, $value);
                    break;
            }
        }
    }

    private static function string(string $name, mixed $value): string
    {
        return is_string($value) ? $value : throw self::reject($name, $value, 'string');
    }

    private static function nullableString(string $name, mixed $value): ?string
    {
        return $value === null || is_string($value) ? $value : throw self::reject($name, $value, '?string');
    }

    private static function nullableInt(string $name, mixed $value): ?int
    {
        return $value === null || is_int($value) ? $value : throw self::reject($name, $value, '?int');
    }

    private static function number(string $name, mixed $value): int|float|null
    {
        return $value === null || is_int($value) || is_float($value) ? $value : throw self::reject($name, $value, 'int|float|null');
    }

    private static function nullableBool(string $name, mixed $value): ?bool
    {
        return $value === null || is_bool($value) ? $value : throw self::reject($name, $value, '?bool');
    }

    private static function timestamp(string $name, mixed $value): \DateTimeImmutable
    {
        return $value instanceof \DateTimeImmutable ? $value : throw self::reject($name, $value, \DateTimeImmutable::class);
    }

    /**
     * @return array<array-key, string>|null
     */
    private static function tags(string $name, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw self::reject($name, $value, '?array');
        }
        $tags = [];
        foreach ($value as $key => $tag) {
            if (!is_string($tag)) {
                throw self::reject($name, $value, '?array');
            }
            $tags[$key] = $tag;
        }

        return $tags;
    }

    private static function reject(string $name, mixed $value, string $type): \TypeError
    {
        return new \TypeError(sprintf('Cannot assign %s to property %s::$%s of type %s', get_debug_type($value), Event::class, $name, $type));
    }
}
