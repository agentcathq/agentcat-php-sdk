<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Json\Json;

final class Golden
{
    public static function path(string $relative): string
    {
        return __DIR__ . '/../Fixtures/golden/' . $relative;
    }

    /** Decodes a golden JSON fixture; objects become \stdClass. */
    public static function json(string $relative): mixed
    {
        return Json::decode(self::text($relative));
    }

    public static function text(string $relative): string
    {
        $contents = file_get_contents(self::path($relative));
        if ($contents === false) {
            throw new \RuntimeException('Cannot read golden fixture ' . $relative);
        }

        return $contents;
    }
}
