<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * How a log line of the heartbeat names what the customer's code threw: by its class, never by its message. The
 * name of an anonymous class holds the path of the file that declares it, so such a class gets a fixed word.
 *
 * @internal
 */
final class ErrorName
{
    public const string ANONYMOUS = 'an anonymous class';

    public static function of(\Throwable $e): string
    {
        return (new \ReflectionClass($e))->isAnonymous() ? self::ANONYMOUS : $e::class;
    }
}
