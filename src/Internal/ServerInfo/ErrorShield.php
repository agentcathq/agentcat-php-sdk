<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * Keeps PHP's warnings and notices of a heartbeat build away from the application, and fails open: one error handler
 * that swallows every error only while the build runs. After `end()` it passes each error to the handler it replaced,
 * with the same arguments, and returns that handler's result, or false (PHP's own handling) when it replaced none.
 *
 * `end()` removes the handler only when it is still on top: a handler that the customer's code set and left above it
 * stays, and a handler below it is never removed. A handler that stays in place therefore changes nothing.
 *
 * @internal
 */
final class ErrorShield
{
    private bool $active = true;

    /** @var (callable(int, string, string, int): mixed)|null */
    private $replaced;

    /** @var \Closure(int, string, string, int): bool */
    private readonly \Closure $handler;

    private function __construct()
    {
        $this->handler = $this->handle(...);
    }

    public static function start(): self
    {
        $shield = new self();
        $shield->replaced = set_error_handler($shield->handler);

        return $shield;
    }

    /** Never throws. After it, the shield swallows nothing. */
    public function end(): void
    {
        $this->active = false;
        $top = set_error_handler(null); // reads the handler on top; the restore takes the null back off
        restore_error_handler();
        if ($top === $this->handler) {
            restore_error_handler();
        }
    }

    /** PHP reads only `false` from a handler (then its own handling runs); the forwarded result keeps that meaning. */
    private function handle(int $severity, string $message, string $file, int $line): bool
    {
        if ($this->active) {
            return true;
        }

        return $this->replaced !== null && ($this->replaced)($severity, $message, $file, $line) !== false;
    }
}
