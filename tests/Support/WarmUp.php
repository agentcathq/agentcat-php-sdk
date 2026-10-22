<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * Runs the warm-up of a bridge test case (`LaravelTestCase`, `SymfonyTestCase`) in `setUpBeforeClass()`, outside a
 * test, where PHPUnit's error handler is off. The warm-up is the first code of the process to include many files of
 * `src/`, so a notice, warning or deprecation that PHP raises once per file (when it includes or compiles it) would
 * reach no test. Such an issue from a file under the source directory is thrown instead, out of `setUpBeforeClass()`:
 * every test of the class errors, and the suite fails. Any other issue (from vendor/, or suppressed with `@`) goes to
 * the handler in place, as without the guard. An issue that reaches a framework's own handler is recorded by the
 * warm-up's `SrcErrorGuard` and fails its `tearDown()` the same way. The handler stacks are put back afterwards, also
 * after a throw.
 */
final class WarmUp
{
    /**
     * @param \Closure(): void $warmUp the test case's lifecycle
     * @param string|null      $source the directory whose files' issues fail the warm-up; null: the repository's `src/`
     */
    public static function run(\Closure $warmUp, ?string $source = null): void
    {
        $source ??= SrcErrorGuard::source();
        $handlers = GlobalHandlers::save(); // the frameworks change them, and no test puts them back
        $inPlace = SrcErrorGuard::inPlace();
        set_error_handler(static function (int $level, string $message, string $file, int $line) use ($source, $inPlace): bool {
            if (SrcErrorGuard::fromSource($level, $file, $source)) {
                throw new \ErrorException(sprintf('%s (raised from %s:%d during a test case warm-up)', $message, $file, $line), 0, $level, $file, $line);
            }

            return SrcErrorGuard::forward($inPlace, $level, $message, $file, $line); // false: PHP's own handling
        });
        try {
            $warmUp();
        } finally {
            $handlers->restore();
        }
    }
}
