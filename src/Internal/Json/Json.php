<?php

declare(strict_types=1);

namespace AgentCat\Internal\Json;

/** @internal */
final class Json
{
    /**
     * Decodes JSON with objects as `\stdClass` (never assoc arrays).
     *
     * @throws \JsonException
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, flags: JSON_THROW_ON_ERROR);
    }

    /** `JSON.stringify` parity. Returns null on any failure; never throws. */
    public static function encodeJs(mixed $value): ?string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** Deep clone of `\stdClass` / list trees; scalars are returned as is. */
    public static function deepCopy(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $child) {
                $copy->{$key} = self::deepCopy($child);
            }

            return $copy;
        }
        if (is_array($value)) {
            return array_map(self::deepCopy(...), $value);
        }

        return $value;
    }
}
