<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * The values of a framework bridge's configuration that every client of the process reads before the environment:
 * with a cached configuration (`php artisan config:cache`), Laravel does not load the
 * `.env` file, so `Env::get()` cannot see what the configuration got from it. The bridge sets them when it boots
 * (`fromBridge()`). The last call wins; they are process-wide, like the environment. `TestMode::configure()` does the
 * same for the switch.
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

    /**
     * Hands a framework bridge's `agentcat` configuration to every client of the process when the bridge boots: the
     * switch, from the D44 words of `enabled` (unset or unknown: no switch,
     * `AGENTCAT_ENABLED` decides), every name AgentCat reads before the environment, and the application's environment
     * for diagnostics. A value that is no string is unset here. Nothing is logged: the bridge's tracker logs the bad
     * values when it tracks a server.
     *
     * @param array<array-key, mixed> $agentcat       the bridge's `agentcat` configuration
     * @param mixed                   $appEnvironment the application's environment: Laravel's `app.env`, Symfony's
     *                                                `kernel.environment`
     */
    public static function fromBridge(array $agentcat, mixed $appEnvironment): void
    {
        TestMode::configure(TestMode::word($agentcat['enabled'] ?? null));
        self::configure([
            'AGENTCAT_PROJECT_ID' => self::string($agentcat['project_id'] ?? null),
            'AGENTCAT_API_URL' => self::string($agentcat['api_url'] ?? null),
            'AGENTCAT_DIAGNOSTICS_ENDPOINT' => self::string($agentcat['diagnostics_endpoint'] ?? null),
            'AGENTCAT_DIAGNOSTICS_TOKEN' => self::string($agentcat['diagnostics_token'] ?? null),
            'APP_ENV' => self::string($appEnvironment),
        ], ConfigOptions::diagnosticsOff($agentcat['disable_diagnostics'] ?? null));
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

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** Tests only: forgets the configured values. */
    public static function reset(): void
    {
        self::$values = [];
        self::$diagnosticsOff = false;
    }
}
