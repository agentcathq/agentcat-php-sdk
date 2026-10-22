<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Class names as error data and log lines show them.
 *
 * @internal
 */
final class ClassName
{
    /**
     * Cuts an anonymous class name at its NUL byte, so it holds no NUL byte, file path or line:
     * `class@anonymous\0/app/a.php:12$0` → `class@anonymous`, `App\Base@anonymous\0/app/b.php:3$1` → `App\Base@anonymous`.
     */
    public static function of(string $class): string
    {
        $name = strstr($class, "\0", true);

        return $name === false ? $class : $name;
    }

    /**
     * Cuts the anonymous class names a function name embeds (PHP 8.4+ closure names):
     * `{closure:class@anonymous\0/app/a.php:12$0::run():13}` → `{closure:class@anonymous::run():13}`.
     */
    public static function inFunction(string $function): string
    {
        return preg_replace('/@anonymous\x00.*?:\d+\$[0-9a-f]+/s', '@anonymous', $function) ?? $function;
    }
}
