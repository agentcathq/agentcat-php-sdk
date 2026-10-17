<?php

declare(strict_types=1);

namespace AgentCat\Internal\Support;

/**
 * Test mode (D31): while a test framework runs, AgentCat instruments nothing and delivers nothing, unless
 * `AGENTCAT_ENABLED` turns it on.
 *
 * @internal
 */
final class TestMode
{
    /**
     * A loaded class of a test runner means tests run. Pest's sequential runner does not define
     * `PHPUNIT_COMPOSER_INSTALL`, so that constant alone is not enough (DECISIONS item 6).
     */
    private const array RUNNER_CLASSES = ['PHPUnit\Framework\TestCase', 'Pest\TestSuite'];

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
     * True when `AGENTCAT_ENABLED` is `true`, `1`, `yes` or `on` (trimmed, any case); otherwise true outside test
     * environments only.
     *
     * @param (\Closure(string): bool)|null $classLoaded see `inTestEnvironment()`
     */
    public static function sdkEnabled(?\Closure $classLoaded = null): bool
    {
        return match (self::normalized('AGENTCAT_ENABLED')) {
            'true', '1', 'yes', 'on' => true,
            default => !self::inTestEnvironment($classLoaded),
        };
    }

    /** Whether the class is loaded already. It never autoloads: an installed but unused PHPUnit does not count. */
    public static function isLoaded(string $class): bool
    {
        return class_exists($class, false);
    }

    private static function normalized(string $name): string
    {
        return strtolower(trim(Env::get($name) ?? ''));
    }
}
