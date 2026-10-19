<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * Runs the warm-up of a bridge test case (`LaravelTestCase`, `SymfonyTestCase`) in `setUpBeforeClass()`, outside a
 * test, where PHPUnit's error handler is off. The warm-up is the first code of the process to include many files of
 * `src/`, so a notice, warning or deprecation that PHP raises once per file (when it includes or compiles it) would
 * reach no test. Such an issue from a file under the source directory is thrown instead, out of `setUpBeforeClass()`:
 * every test of the class errors, and the suite fails. Any other issue (from vendor/, or suppressed with `@`) goes to
 * the handler in place, as without the guard. The handler stacks are put back afterwards, also after a throw.
 */
final class WarmUp
{
    /** The issues that fail the warm-up. */
    private const int ISSUES = E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED;

    /** What `@` leaves of `error_reporting()`: with no other level, the issue is suppressed (PHPUnit's own test). */
    private const int INSUPPRESSIBLE = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /**
     * @param \Closure(): void $warmUp the test case's lifecycle
     * @param string|null      $source the directory whose files' issues fail the warm-up; null: the repository's `src/`
     */
    public static function run(\Closure $warmUp, ?string $source = null): void
    {
        $source ??= dirname(__DIR__, 2) . '/src/';
        $handlers = GlobalHandlers::save(); // the frameworks change them, and no test puts them back
        $inPlace = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        set_error_handler(static function (int $level, string $message, string $file, int $line) use ($source, $inPlace): bool {
            if (($level & self::ISSUES) !== 0 && (error_reporting() & ~self::INSUPPRESSIBLE) !== 0 && str_starts_with($file, $source)) {
                throw new \ErrorException(sprintf('%s (raised from %s:%d during a test case warm-up)', $message, $file, $line), 0, $level, $file, $line);
            }

            return $inPlace !== null && $inPlace($level, $message, $file, $line) !== false; // false: PHP's own handling
        });
        try {
            $warmUp();
        } finally {
            $handlers->restore();
        }
    }
}
