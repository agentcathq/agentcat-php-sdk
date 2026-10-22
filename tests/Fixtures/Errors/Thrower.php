<?php

declare(strict_types=1);

namespace AgentCat\Tests\Fixtures\Errors;

/** Throws from known places; ExceptionCaptureTest reads the recorded line numbers. */
final class Thrower
{
    public static int $callLine = 0;

    public function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }

    public static function viaStatic(string $message): never
    {
        self::$callLine = __LINE__ + 1;
        (new self())->fail($message);
    }

    /** @param int<1, max> $depth */
    public static function recurse(int $depth): never
    {
        if ($depth <= 1) {
            throw new \LogicException('deep');
        }
        self::recurse($depth - 1);
    }
}
