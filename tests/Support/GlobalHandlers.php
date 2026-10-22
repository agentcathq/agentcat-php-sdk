<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * The process's error and exception handler stacks, saved and put back around code that runs outside a test (the
 * warm-ups of `LaravelTestCase` and `SymfonyTestCase`). PHPUnit compares the stacks before and after each test, so a
 * handler that such code leaves (Symfony's `ErrorHandler`) or removes (Laravel's `HandleExceptions::flushState()`)
 * would make later tests risky. PHP 8.3 cannot read a stack without changing it: each one is taken apart and built
 * again, as PHPUnit's `TestCase` does.
 */
final readonly class GlobalHandlers
{
    /**
     * @param list<callable(int, string, string, int): bool> $errors     from the bottom of the stack up
     * @param list<callable(\Throwable): void>              $exceptions from the bottom of the stack up
     */
    private function __construct(
        private array $errors,
        private array $exceptions,
    ) {}

    public static function save(): self
    {
        $errors = self::takeErrorHandlers();
        $exceptions = self::takeExceptionHandlers();
        self::push($errors, $exceptions);

        return new self($errors, $exceptions);
    }

    /** Puts back the stacks as they were saved. */
    public function restore(): void
    {
        self::takeErrorHandlers();
        self::takeExceptionHandlers();
        self::push($this->errors, $this->exceptions);
    }

    /** @return list<callable(int, string, string, int): bool> the error handlers, from the bottom up; the stack is left empty */
    private static function takeErrorHandlers(): array
    {
        $handlers = [];
        while (true) {
            $previous = set_error_handler(static fn(): bool => false);
            restore_error_handler();
            if ($previous === null) {
                break;
            }
            $handlers[] = $previous;
            restore_error_handler();
        }

        return array_reverse($handlers);
    }

    /** @return list<callable(\Throwable): void> the exception handlers, from the bottom up; the stack is left empty */
    private static function takeExceptionHandlers(): array
    {
        $handlers = [];
        while (true) {
            $previous = set_exception_handler(static function (): void {});
            restore_exception_handler();
            if ($previous === null) {
                break;
            }
            $handlers[] = $previous;
            restore_exception_handler();
        }

        return array_reverse($handlers);
    }

    /**
     * @param list<callable(int, string, string, int): bool> $errors
     * @param list<callable(\Throwable): void>              $exceptions
     */
    private static function push(array $errors, array $exceptions): void
    {
        foreach ($errors as $handler) {
            set_error_handler($handler);
        }
        foreach ($exceptions as $handler) {
            set_exception_handler($handler);
        }
    }
}
