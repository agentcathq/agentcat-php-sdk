<?php

declare(strict_types=1);

namespace AgentCat\Internal\Id;

/** @internal */
final class EventId
{
    public static function mint(): string
    {
        return 'evt_' . Ksuid::random();
    }
}
