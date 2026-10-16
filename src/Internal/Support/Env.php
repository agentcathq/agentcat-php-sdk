<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Environment variable lookup that works under CLI, PHP-FPM and `variables_order` without `E`.
 *
 * @internal
 */
final class Env
{
    /** The first non-empty string among `getenv()`, `$_ENV` and `$_SERVER` (JS `||` skips empty strings). */
    public static function get(string $name): ?string
    {
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
