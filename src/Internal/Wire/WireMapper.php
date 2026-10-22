<?php

declare(strict_types=1);

namespace AgentCat\Internal\Wire;

use AgentCat\Api\Model\PublishEventRequest;
use AgentCat\Event;

/** @internal */
final class WireMapper
{
    /** 2^63, the first float past `PHP_INT_MAX`; `-2^63` is `PHP_INT_MIN` itself. */
    private const float INT_RANGE_END = 9.223372036854775808E18;

    /**
     * @throws \UnexpectedValueException when a free-form field is circular or nested too deeply
     */
    public static function toRequest(Event $event): PublishEventRequest
    {
        /** @var array<string, string>|null $tags */
        $tags = $event->tags;

        return new PublishEventRequest(
            projectId: $event->projectId ?? '',
            id: $event->id,
            sessionId: $event->sessionId === '' ? null : $event->sessionId,
            actorId: ($event->actorId ?? '') !== '' ? $event->actorId : $event->identifyActorGivenId,
            eventType: $event->eventType,
            isError: $event->isError,
            error: self::freeForm($event->error),
            resourceName: $event->resourceName,
            duration: self::duration($event->duration),
            inputTokens: $event->inputTokens,
            outputTokens: $event->outputTokens,
            timestamp: $event->timestamp,
            userIntent: $event->userIntent,
            parameters: self::freeForm($event->parameters),
            response: self::freeForm($event->response),
            identifyActorGivenId: $event->identifyActorGivenId,
            identifyActorName: $event->identifyActorName,
            identifyData: self::freeForm($event->identifyActorData),
            tags: $tags, // values are strings by type; the model casts the map to an object
            properties: self::freeForm($event->properties),
            ipAddress: $event->ipAddress,
            sdkLanguage: $event->sdkLanguage,
            agentcatVersion: $event->agentcatVersion,
            serverName: $event->serverName,
            serverVersion: $event->serverVersion,
            clientName: $event->clientName,
            clientVersion: $event->clientVersion,
        );
    }

    /**
     * Wire keys left out of the body because their value is a scalar (the API accepts only objects there).
     *
     * @return list<string>
     */
    public static function droppedFields(Event $event): array
    {
        $dropped = [];
        $candidates = [
            'error' => $event->error,
            'parameters' => $event->parameters,
            'response' => $event->response,
            'identify_data' => $event->identifyActorData,
            'properties' => $event->properties,
        ];
        foreach ($candidates as $key => $value) {
            if ($value !== null && !self::freeForm($value) instanceof \stdClass) {
                $dropped[] = $key;
            }
        }

        return $dropped;
    }

    private static function freeForm(mixed $value): ?\stdClass
    {
        $wire = WireValue::from($value);
        if ($wire instanceof \stdClass) {
            return $wire;
        }

        return is_array($wire) ? (object) $wire : null;
    }

    /**
     * A float duration is rounded. NaN, an infinity or a float outside the int range has no duration: casting it
     * gives a meaningless int, and PHP 8.5 also prints a warning (on STDOUT in a CLI server).
     */
    private static function duration(int|float|null $duration): ?int
    {
        if (!is_float($duration)) {
            return $duration;
        }
        $rounded = round($duration);

        return $rounded >= -self::INT_RANGE_END && $rounded < self::INT_RANGE_END ? (int) $rounded : null; // false for NaN
    }
}
