<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

use AgentCat\Internal\Logging\Log;

/**
 * When AgentCat instruments and delivers. `AGENTCAT_ENABLED` decides first (D44): a false value turns AgentCat off
 * everywhere, a true value turns it on everywhere. Otherwise AgentCat is off while a test framework runs (D31) and
 * on everywhere else.
 *
 * @internal
 */
final class TestMode
{
    private const string UNKNOWN_VALUE = 'WARN: AGENTCAT_ENABLED "%s" is not one of true, 1, yes, on, false, 0, no, off; the default is used';

    /**
     * A loaded class of a test runner means tests run. Pest's sequential runner does not define
     * `PHPUNIT_COMPOSER_INSTALL`, so that constant alone is not enough (DECISIONS item 6).
     */
    private const array RUNNER_CLASSES = ['PHPUnit\Framework\TestCase', 'Pest\TestSuite'];

    /** True once an unknown `AGENTCAT_ENABLED` value was logged: the line appears once per process. */
    private static bool $reported = false;

    /**
     * True when a test runner class is loaded, or `APP_ENV` is `testing` or `test` (trimmed, any case).
     *
     * @param (\Closure(string): bool)|null $classLoaded tells whether a class is loaded; default `isLoaded()`
     */
    public static function inTestEnvironment(?\Closure $classLoaded = null): bool
    {
        $classLoaded ??= self::isLoaded(...);
        foreach (self::RUNNER_CLASSES as $class) {
            if ($classLoaded($class)) {
                return true;
            }
        }

        return match (self::normalized('APP_ENV')) {
            'testing', 'test' => true,
            default => false,
        };
    }

    /**
     * True when `AGENTCAT_ENABLED` is `true`, `1`, `yes` or `on`; false when it is `false`, `0`, `no` or `off`
     * (trimmed, any case). Unset or empty: true outside test environments only. Any other value counts as unset and
     * logs one line, once per process.
     *
     * @param (\Closure(string): bool)|null $classLoaded see `inTestEnvironment()`
     */
    public static function sdkEnabled(?\Closure $classLoaded = null): bool
    {
        $value = self::normalized('AGENTCAT_ENABLED');

        return match ($value) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => self::byDefault($value, $classLoaded),
        };
    }

    /** Whether the class is loaded already. It never autoloads: an installed but unused PHPUnit does not count. */
    public static function isLoaded(string $class): bool
    {
        return class_exists($class, false);
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$reported = false;
    }

    /** @param (\Closure(string): bool)|null $classLoaded */
    private static function byDefault(string $value, ?\Closure $classLoaded): bool
    {
        if ($value !== '' && !self::$reported) {
            self::$reported = true;
            Log::write(sprintf(self::UNKNOWN_VALUE, Env::get('AGENTCAT_ENABLED')));
        }

        return !self::inTestEnvironment($classLoaded);
    }

    private static function normalized(string $name): string
    {
        return strtolower(trim(Env::get($name) ?? ''));
    }
}
