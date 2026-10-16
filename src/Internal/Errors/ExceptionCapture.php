<?php

declare(strict_types=1);

namespace AgentCat\Internal\Errors;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Support\Js;
use AgentCat\Internal\Support\Values;

/**
 * Builds the `error` object of an event (TS `captureException`).
 *
 * ErrorData keys, in order: `message`, `type`, `platform`, `stack`, `frames`, `chained_errors` (only when there
 * is a previous exception). A chained entry has `message`, `type`, `stack` and `frames`. The server needs a string
 * `message` and string `filename` and `function` on every frame, or it discards the whole error.
 *
 * @internal
 */
final class ExceptionCapture
{
    public const string PLATFORM = 'php';
    public const int MAX_CHAINED_ERRORS = 10;

    /**
     * An `isError` tool result gives its text as the message; a `\Throwable` gives full data; any other value is
     * stringified like TS `stringifyNonError`. Never throws.
     */
    public static function capture(mixed $error, ?StackFrames $frames = null): \stdClass
    {
        $content = self::toolResultContent($error);
        if ($content !== null) {
            return self::fromToolResult($content);
        }
        if ($error instanceof \Throwable) {
            return self::fromThrowable($error, $frames);
        }
        $data = new \stdClass();
        $data->message = self::stringify($error);
        $data->platform = self::PLATFORM;

        return $data;
    }

    /** Never throws: if capture itself fails, the data holds only the message, type and platform. */
    public static function fromThrowable(\Throwable $error, ?StackFrames $frames = null): \stdClass
    {
        try {
            $frames ??= StackFrames::default();
            $data = new \stdClass();
            $data->message = $error->getMessage();
            $data->type = $error::class;
            $data->platform = self::PLATFORM;
            $data->stack = StackFrames::stackString($error);
            $data->frames = $frames->build($error);
            $chained = self::chain($error, $frames);
            if ($chained !== []) {
                $data->chained_errors = $chained;
            }

            return $data;
        } catch (\Throwable) {
            $fallback = new \stdClass();
            $fallback->message = $error->getMessage();
            $fallback->type = $error::class;
            $fallback->platform = self::PLATFORM;

            return $fallback;
        }
    }

    /** TS `isCallToolResult`: an object or array with `isError` and a list `content` (`isError: false` matches too). */
    public static function isToolResult(mixed $value): bool
    {
        return self::toolResultContent($value) !== null;
    }

    /** @return list<mixed>|null */
    private static function toolResultContent(mixed $value): ?array
    {
        if (!Values::isObjectLike($value) || !Values::has($value, 'isError')) {
            return null;
        }
        $content = Values::get($value, 'content');

        return is_array($content) && array_is_list($content) ? $content : null;
    }

    /** @param list<mixed> $content */
    private static function fromToolResult(array $content): \stdClass
    {
        $texts = [];
        foreach ($content as $block) {
            if (Values::isObjectLike($block) && Values::get($block, 'type') === 'text') {
                $text = Values::get($block, 'text');
                $texts[] = $text === null ? '' : Js::string($text);
            }
        }
        $message = Js::trim(implode(' ', $texts));
        $data = new \stdClass();
        $data->message = $message === '' ? 'Unknown error' : $message;
        $data->platform = self::PLATFORM;

        return $data;
    }

    /**
     * The `getPrevious()` chain, outermost first, at most `MAX_CHAINED_ERRORS` entries. Like TS, the seen-set does
     * not hold the top-level error, so a cycle through it repeats it once (D15).
     *
     * @return list<\stdClass>
     */
    private static function chain(\Throwable $error, StackFrames $frames): array
    {
        /** @var \WeakMap<\Throwable, true> $seen */
        $seen = new \WeakMap();
        $chained = [];
        $current = $error->getPrevious();
        while ($current instanceof \Throwable && count($chained) < self::MAX_CHAINED_ERRORS && !isset($seen[$current])) {
            $seen[$current] = true;
            $entry = new \stdClass();
            $entry->message = $current->getMessage();
            $entry->type = $current::class;
            $entry->stack = StackFrames::stackString($current);
            $entry->frames = $frames->build($current);
            $chained[] = $entry;
            $current = $current->getPrevious();
        }

        return $chained;
    }

    /** TS `stringifyNonError`. */
    private static function stringify(mixed $value): string
    {
        if ($value === null || is_scalar($value)) {
            return Js::string($value);
        }

        return Json::encodeJs($value) ?? '[object Object]';
    }
}
