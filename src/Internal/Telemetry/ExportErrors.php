<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

use AgentCat\Internal\Support\ErrorText;

/**
 * The two ways TypeScript prints an export error: `${error}` and `error.message`.
 *
 * @internal
 */
final class ExportErrors
{
    /** JS `${error}`. */
    public static function text(\Throwable $error): string
    {
        return $error instanceof JsError ? $error->name . ': ' . $error->getMessage() : ErrorText::of($error);
    }

    /** JS `error.message`. */
    public static function message(\Throwable $error): string
    {
        return $error instanceof JsError ? $error->getMessage() : ErrorText::message($error);
    }
}
