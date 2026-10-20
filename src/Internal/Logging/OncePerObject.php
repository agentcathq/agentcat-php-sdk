<?php

declare(strict_types=1);

namespace AgentCat\Internal\Logging;

/**
 * Warnings that are logged once per object, for example the compatibility line of an adapter that cannot read a
 * server on the request path: the first one for an object is logged, later ones with the same
 * line are not. Entries go away with their object.
 *
 * @internal
 */
final class OncePerObject
{
    /** @var \WeakMap<object, array<string, true>>|null the lines logged for each object */
    private static ?\WeakMap $logged = null;

    public static function warn(object $subject, string $line): void
    {
        self::$logged ??= new \WeakMap();
        $lines = self::$logged[$subject] ?? [];
        if (isset($lines[$line])) {
            return;
        }
        $lines[$line] = true;
        self::$logged[$subject] = $lines;
        Log::warning($line);
    }
}
