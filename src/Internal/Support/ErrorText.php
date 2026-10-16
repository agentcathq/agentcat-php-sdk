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
        return ClassName::of($error::class) . ': ' . $error->getMessage();
    }
}
