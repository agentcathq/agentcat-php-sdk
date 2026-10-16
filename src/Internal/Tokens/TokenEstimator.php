<?php

declare(strict_types=1);

namespace AgentCat\Internal\Tokens;

use AgentCat\Internal\Json\Json;

/**
 * Port of the TypeScript token estimates (3.5 UTF-8 bytes per token).
 *
 * @internal
 */
final class TokenEstimator
{
    private const float BYTES_PER_TOKEN = 3.5;
    private const int MAX_TOKEN_COUNT = 2147483647;

    public static function estimate(int $bytes): int
    {
        return $bytes > 0 ? min((int) ceil($bytes / self::BYTES_PER_TOKEN), self::MAX_TOKEN_COUNT) : 0;
    }

    /** Tokens of the JSON-serialized tool arguments; null when absent or not serializable. */
    public static function input(mixed $arguments): ?int
    {
        if ($arguments === null) {
            return null;
        }

        return self::ofJson($arguments);
    }

    /** Tokens of a tool result; null when absent or not serializable. */
    public static function output(mixed $response): ?int
    {
        try {
            if ($response === null) {
                return null;
            }
            if (!$response instanceof \stdClass || !is_array($response->content ?? null)) {
                return self::ofJson($response);
            }
            $content = $response->content;
            if ($content === []) {
                $structured = $response->structuredContent ?? null;

                return $structured === null ? 0 : self::ofJson($structured);
            }

            $bytes = 0;
            foreach ($content as $block) {
                $bytes += self::blockBytes($block);
            }

            return self::estimate($bytes);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function ofJson(mixed $value): ?int
    {
        $json = Json::encodeJs($value);

        return $json === null ? null : self::estimate(strlen($json));
    }

    private static function blockBytes(mixed $block): int
    {
        if (!$block instanceof \stdClass) {
            return 0;
        }
        $text = match ($block->type ?? null) {
            'text' => $block->text ?? null,
            'resource' => ($block->resource ?? null) instanceof \stdClass ? ($block->resource->text ?? null) : null,
            default => null,
        };

        return is_string($text) ? strlen($text) : 0;
    }
}
