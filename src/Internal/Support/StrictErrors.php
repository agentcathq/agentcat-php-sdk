<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Runs code with every PHP warning, notice and deprecation turned into an `\ErrorException`. The private-member
 * probe uses it, so a renamed or removed member throws instead of warning.
 *
 * @internal
 */
final class StrictErrors
{
    /**
     * @template T
     *
     * @param \Closure(): T $code
     *
     * @return T
     *
     * @throws \ErrorException for the first warning, notice or deprecation inside `$code`; the previous error handler
     *                         is active again afterwards
     */
    public static function run(\Closure $code): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $code();
        } finally {
            restore_error_handler();
        }
    }
}
