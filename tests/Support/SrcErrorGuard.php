<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Makes a notice, warning or deprecation that a file of `src/` raises while a framework's own error handler is in place
 * fail the test. Laravel's `HandleExceptions` writes a deprecation to a log channel and turns any other issue into an
 * exception, which a request answers with an error; Symfony's `ErrorHandler` can log or swallow an issue. Either
 * way, PHPUnit never sees it. After each application or kernel of a bridge test case boots, `install()` puts a handler
 * in front of the framework's: it records an issue from a file under the source directory, then hands every issue to
 * the framework's handler, as without the guard. The test case's `tearDown()` puts the handler stack back
 * (`GlobalHandlers`), and an `#[After]` method calls `assertNone()` once every `tearDown()` of the test has run.
 */
final class SrcErrorGuard
{
    /** The issues that a file of the source directory must not raise: notices, warnings and deprecations. */
    private const int ISSUES = E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED;

    /** What `@` leaves of `error_reporting()`: with no other level, the issue is suppressed (PHPUnit's own test). */
    private const int INSUPPRESSIBLE = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    private const array LEVELS = [
        E_NOTICE => 'E_NOTICE',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_WARNING => 'E_WARNING',
        E_USER_WARNING => 'E_USER_WARNING',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    ];

    /** @var list<string> each recorded issue: its level, message and `file:line` */
    private static array $recorded = [];

    /** @var list<\Closure(int, string, string, int): bool> the guards installed since the last `assertNone()` */
    private static array $guards = [];

    /**
     * Puts a guard in front of the error handler in place, unless a guard is already in front. The guard calls that
     * handler itself: a handler that returns false hands the issue to PHP's own handling, not to the one below it.
     *
     * @param string|null $source the directory whose files' issues are recorded; null: the repository's `src/`
     */
    public static function install(?string $source = null): void
    {
        if (self::inFront()) {
            return; // a second kernel of the same test, or after the boot: no handler came in front of the guard
        }
        $inPlace = self::inPlace();
        $source ??= self::source();
        $guard = static function (int $level, string $message, string $file, int $line) use ($source, $inPlace): bool {
            if (self::fromSource($level, $file, $source)) {
                self::$recorded[] = sprintf('%s: %s at %s:%d', self::LEVELS[$level] ?? (string) $level, $message, $file, $line);
            }

            return self::forward($inPlace, $level, $message, $file, $line);
        };
        self::$guards[] = $guard;
        set_error_handler($guard);
    }

    /** Fails with every recorded issue; counts as one assertion. It forgets the issues and the guards either way. */
    public static function assertNone(TestCase $test): void
    {
        $recorded = self::$recorded;
        self::$recorded = [];
        self::$guards = [];
        $test::assertCount(0, $recorded, sprintf("A file of src/ raised %d issue(s) that a framework's error handler took:\n%s", count($recorded), implode("\n", $recorded)));
    }

    /**
     * Forgets the issues and the guards of a test that ended before its `assertNone()`: its `tearDown()` threw, so it
     * failed already. A bridge test case calls it first in `setUp()`, so no other test reports them.
     */
    public static function forget(): void
    {
        self::$recorded = [];
        self::$guards = [];
    }

    /** The guards installed since the last `assertNone()`: one for each application or kernel handler they front. */
    public static function installed(): int
    {
        return count(self::$guards);
    }

    /** Whether a guard is the error handler in place. */
    public static function inFront(): bool
    {
        return in_array(self::inPlace(), self::$guards, true);
    }

    /** The repository's `src/`, with a trailing slash. */
    public static function source(): string
    {
        return dirname(__DIR__, 2) . '/src/';
    }

    /**
     * Whether an issue is a notice, warning or deprecation, not suppressed with `@`, from a file under `$source` (both
     * compared by their real paths).
     */
    public static function fromSource(int $level, string $file, string $source): bool
    {
        if (($level & self::ISSUES) === 0 || (error_reporting() & ~self::INSUPPRESSIBLE) === 0) {
            return false;
        }
        $path = realpath($file);
        $directory = realpath($source);

        return str_starts_with($path === false ? $file : $path, ($directory === false ? rtrim($source, '/') : $directory) . '/');
    }

    /**
     * The error handler in place (PHP 8.3 has no `get_error_handler()`).
     *
     * @return (callable(int, string, string, int): bool)|null
     */
    public static function inPlace(): ?callable
    {
        $inPlace = set_error_handler(static fn(): bool => false);
        restore_error_handler();

        return $inPlace;
    }

    /**
     * Hands an issue to the handler that was in place, as PHP would have: true when it took the issue, false (PHP's own
     * handling) when it declined it or there was none. Every level goes to it: PHP does not tell which levels a handler
     * was registered for (`set_error_handler()`'s second argument), and no framework in use registers one.
     *
     * @param (callable(int, string, string, int): bool)|null $inPlace
     */
    public static function forward(?callable $inPlace, int $level, string $message, string $file, int $line): bool
    {
        return $inPlace !== null && $inPlace($level, $message, $file, $line) !== false;
    }
}
