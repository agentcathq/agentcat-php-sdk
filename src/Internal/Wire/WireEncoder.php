<?php

declare(strict_types=1);

namespace AgentCat\Internal\Wire;

use AgentCat\Event;

/** @internal */
final class WireEncoder
{
    public const int FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @throws \JsonException
     * @throws \UnexpectedValueException
     */
    public static function encode(Event $event): string
    {
        return json_encode(WireMapper::toRequest($event), self::FLAGS);
    }

    /**
     * @throws \JsonException
     * @throws \UnexpectedValueException
     */
    public static function byteSize(Event $event): int
    {
        return strlen(self::encode($event));
    }
}
