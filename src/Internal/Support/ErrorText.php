<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * The text SDK log lines use where the TypeScript SDK writes `${error}`.
 *
 * @internal
 */
final class ErrorText
{
    public static function of(\Throwable $error): string
    {
        return ClassName::of($error::class) . ': ' . self::message($error);
    }

    /**
     * `getMessage()` that never throws or warns. A legacy subclass can store a non-string in `$message`: an array
     * reads as `Array`, an object without `__toString()` as `''`.
     */
    public static function message(\Throwable $error): string
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $error->getMessage();
        } catch (\Throwable) { // @phpstan-ignore catch.neverThrown (a legacy `$message` object without __toString() throws)
            return '';
        } finally {
            restore_error_handler();
        }
    }
}
