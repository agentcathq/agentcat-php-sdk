<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * What the last shutdown pass does on a web SAPI before it delivers (D57): it writes and closes an active native
 * session, so its lock is not held while AgentCat delivers; it gives the drain its own time limit, so a request that
 * used most of `max_execution_time` does not end the drain with a fatal error; and, without a finish-request function,
 * it sends the output buffers, so a response that carries a Content-Length reaches the client before hooks and delivery
 * run, unless PHP cannot ignore an aborted client. Nothing runs on the command line. Every step is guarded: a failing
 * step skips only itself.
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
        $ini = $exists('ini_set');

        return new self(
            $sapi,
            $finishes,
            static fn(): bool => $session && session_status() === PHP_SESSION_ACTIVE,
            static function (): void {
                session_write_close();
            },
            static fn(): int => (int) ini_get('max_execution_time'),
            static function (int $seconds) use ($limit): void {
                if ($limit) {
                    set_time_limit($seconds);
                }
            },
            static function () use ($abort, $ini): void {
                if (self::ignoreUserAbort($abort, $ini)) {
                    self::closeOutputBuffers();
                }
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
     * On a SAPI that sends a response only when the script ends: whether the client has the whole answer although the
     * script still runs. The headers are out with a `Content-Length`, so the client knows where the answer ends, and no
     * output buffer holds bytes of it back. Never throws: false when a function it needs is missing or fails.
     *
     * @param (\Closure(): bool)|null         $headersSent default `headers_sent()`
     * @param (\Closure(): list<string>)|null $headers     the answer's header lines; default `headers_list()`
     * @param (\Closure(): int)|null          $bufferLevel default `ob_get_level()`
     */
    public static function answerSent(?\Closure $headersSent = null, ?\Closure $headers = null, ?\Closure $bufferLevel = null): bool
    {
        try {
            if (!($headersSent ?? headers_sent(...))() || ($bufferLevel ?? ob_get_level(...))() !== 0) {
                return false;
            }
            foreach (($headers ?? headers_list(...))() as $line) {
                if (stripos($line, 'Content-Length:') === 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // a disabled function: the answer counts as not sent
        }

        return false;
    }

    /**
     * Sends and closes the removable output buffers above `$keep` (Symfony `Response::closeOutputBuffers()`), from the
     * top down to the first one that may not be removed or flushed, then flushes the SAPI.
     */
    public static function closeOutputBuffers(int $keep = 0): void
    {
        for ($level = ob_get_level(); $level > $keep && self::canSendTopBuffer(); --$level) { // at most one pass per buffer
            ob_end_flush();
        }
        flush();
    }

    /** The buffer on top may be removed and flushed (Symfony's rule), so `ob_end_flush()` closes it without a notice. */
    private static function canSendTopBuffer(): bool
    {
        $flags = ob_get_status()['flags'] ?? null;
        $required = PHP_OUTPUT_HANDLER_REMOVABLE | PHP_OUTPUT_HANDLER_FLUSHABLE;

        return is_int($flags) && ($flags & $required) === $required;
    }

    /**
     * Lets the script go on when the client leaves once it has the body. On mod_php and php-cgi, a write to a client
     * that left ends the script at once unless PHP ignores the abort, so sending the buffers would end the shutdown
     * pass before delivery. With `ignore_user_abort()` disabled, `ini_set()` still switches the setting on. True when
     * PHP ignores the abort now.
     */
    private static function ignoreUserAbort(bool $function, bool $ini): bool
    {
        if ($function) {
            ignore_user_abort(true);
        } elseif ($ini) {
            ini_set('ignore_user_abort', '1');
        }

        return ini_get('ignore_user_abort') === '1'; // false also when an administrator locked the setting off
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
