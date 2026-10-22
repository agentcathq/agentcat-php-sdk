<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Environment variable lookup that works under CLI, PHP-FPM, `variables_order` without `E`, and on a host that
 * disables `getenv()`.
 *
 * @internal
 */
final class Env
{
    /** The first non-empty string among `getenv()`, `$_ENV` and `$_SERVER` (JS `||` skips empty strings). */
    public static function get(string $name): ?string
    {
        foreach ([self::lookup($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * `getenv($name)`: the value, or false when the variable is not set. A host can disable `getenv()`
     * (`disable_functions`); the value then comes from `$_ENV`, else `$_SERVER`, where the CLI and PHP-FPM copy the
     * process environment.
     *
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether `getenv()` exists; default `function_exists()`
     */
    public static function lookup(string $name, ?\Closure $functionExists = null): string|false
    {
        if (($functionExists ?? function_exists(...))('getenv')) {
            return getenv($name);
        }
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($value) ? $value : false;
    }
}
