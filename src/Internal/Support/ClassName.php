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
}
