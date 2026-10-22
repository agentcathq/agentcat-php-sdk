<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * The values of a framework bridge's configuration that every client of the process reads before the environment
 * (plan E1 header conflict 12): with a cached configuration (`php artisan config:cache`), Laravel does not load the
 * `.env` file, so `Env::get()` cannot see what the configuration got from it. The bridge sets them when it boots. The
 * last call wins; they are process-wide, like the environment. `TestMode::configure()` does the same for the switch.
 *
 * @internal
 */
final class BridgeConfig
{
    /** @var array<string, string> environment name => the value of the configuration */
    private static array $values = [];

    private static bool $diagnosticsOff = false;

    /**
     * @param array<string, string|null> $values         environment name => the value the configuration holds for it;
     *                                                   null or '' (`env()` of an unset variable) leaves it to the
     *                                                   environment
     * @param bool                       $diagnosticsOff whether the configuration turns diagnostics off
     */
    public static function configure(array $values, bool $diagnosticsOff): void
    {
        self::$values = [];
        foreach ($values as $name => $value) {
            if ($value !== null && $value !== '') {
                self::$values[$name] = $value;
            }
        }
        self::$diagnosticsOff = $diagnosticsOff;
    }

    /** The configured value of an environment name, else `Env::get()`. */
    public static function env(string $name): ?string
    {
        return self::$values[$name] ?? Env::get($name);
    }

    /** Whether the configuration turns diagnostics off. It never turns them on: off wins (D50). */
    public static function diagnosticsOff(): bool
    {
        return self::$diagnosticsOff;
    }

    /** Tests only: forgets the configured values. */
    public static function reset(): void
    {
        self::$values = [];
        self::$diagnosticsOff = false;
    }
}
