<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * What the last shutdown pass does on a web SAPI before it delivers (plan E1 header conflicts 14 and 15, D57): it
 * writes and closes an active native session, so its lock is not held while AgentCat delivers; it gives the drain its
 * own time limit, so a request that used most of `max_execution_time` does not end the drain with a fatal error; and,
 * without a finish-request function, it sends the output buffers, so a response that carries a Content-Length
 * reaches the client before hooks and delivery run. Nothing runs on the command line. Every step is guarded: a
 * failing step skips only itself.
 *
 * @internal
 */
final readonly class RequestEnd
{
    /**
     * @param \Closure(): bool    $sessionActive
     * @param \Closure(): void    $closeSession
     * @param \Closure(): int     $timeLimit     the request's time limit in seconds, 0 for none
     * @param \Closure(int): void $setTimeLimit
     * @param \Closure(): void    $closeOutput
     */
    public function __construct(
        private string $sapi,
        private bool $finishes,
        private \Closure $sessionActive,
        private \Closure $closeSession,
        private \Closure $timeLimit,
        private \Closure $setTimeLimit,
        private \Closure $closeOutput,
    ) {}

    /**
     * The real PHP functions; a step whose function is missing or disabled is skipped.
     *
     * @param bool                          $finishes       whether the SAPI has a finish-request function (`FinishRequest::detect()`)
     * @param (\Closure(string): bool)|null $functionExists default `function_exists(...)`
     */
    public static function detect(bool $finishes, string $sapi = PHP_SAPI, ?\Closure $functionExists = null): self
    {
        $exists = $functionExists ?? function_exists(...);
        $session = $exists('session_status') && $exists('session_write_close');
        $limit = $exists('set_time_limit');
        $abort = $exists('ignore_user_abort');

        return new self(
            $sapi,
            $finishes,
            static fn(): bool => $session && session_status() === PHP_SESSION_ACTIVE,
            static function (): void {
                session_write_close();
            },
            static fn(): int => $limit ? (int) ini_get('max_execution_time') : 0,
            static function (int $seconds): void {
                set_time_limit($seconds);
            },
            static function () use ($abort): void {
                if ($abort) {
                    ignore_user_abort(true); // the client may leave once it has the body
                }
                self::closeOutputBuffers();
            },
        );
    }

    public function prepare(float $budgetSeconds): void
    {
        if ($this->sapi === 'cli' || $this->sapi === 'phpdbg') {
            return; // stdio servers: stdout is the protocol, and there is no request to end
        }

        try {
            if (($this->sessionActive)()) {
                ($this->closeSession)();
            }
        } catch (\Throwable) {
            // the next step still runs
        }

        try {
            if (($this->timeLimit)() > 0) {
                ($this->setTimeLimit)($this->timeLimitFor($budgetSeconds));
            }
        } catch (\Throwable) {
            // the next step still runs
        }
        if ($this->finishes) {
            return; // the finish call sends the response
        }

        try {
            ($this->closeOutput)();
        } catch (\Throwable) {
            // delivery matters more than the buffers
        }
    }

    /**
     * Sends and closes the removable output buffers above `$keep` (Symfony `Response::closeOutputBuffers()`), from the
     * top down to the first one that may not be removed or flushed, then flushes the SAPI.
     */
    public static function closeOutputBuffers(int $keep = 0): void
    {
        $required = PHP_OUTPUT_HANDLER_REMOVABLE | PHP_OUTPUT_HANDLER_FLUSHABLE;
        for ($level = ob_get_level(); $level > $keep; --$level) { // at most one pass per buffer
            $flags = ob_get_status()['flags'] ?? null; // the buffer on top
            if (!is_int($flags) || ($flags & $required) !== $required || !ob_end_flush()) {
                break;
            }
        }
        flush();
    }

    /**
     * The budget in whole seconds, rounded up, plus one. A negative budget counts as none; one that is not finite, or
     * that `(int)` cannot convert (it warns on PHP 8.5), counts as the shutdown timeout.
     */
    private function timeLimitFor(float $budgetSeconds): int
    {
        $budget = is_finite($budgetSeconds) && $budgetSeconds < PHP_INT_MAX ? max(0.0, $budgetSeconds) : EventQueue::SHUTDOWN_TIMEOUT_SECONDS;

        return (int) ceil($budget) + 1;
    }
}
