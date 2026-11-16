<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * Keeps what the customer's code prints during a heartbeat build out of the output of the process, and fails open: one
 * output buffer whose handler drops what reaches it only while the build runs. `end()` clears that flag first, so from
 * then on the handler passes on every byte unchanged, whatever the cleanup after it does. The buffer hands on each write
 * at once (chunk size 1), so it holds nothing back when it has to stay.
 *
 * The cleanup removes the buffer and every buffer that the customer's code left open above it, with one try for each.
 * It stops at the first buffer that is still there after its try (PHP cannot remove it): that buffer stays, with the
 * guard's buffer below it as a pass-through. What the customer's code printed into such a buffer can then reach the output
 * late; the guard never cuts a byte from output that comes after the build.
 *
 * @internal
 */
final class OutputGuard
{
    /** True only while the build runs: what reaches the buffer is dropped. */
    private bool $building = true;

    private bool $buffered = false;

    private function __construct(private readonly int $level) {}

    /** Call it under an error handler that takes PHP's notices: a buffer that cannot be removed raises one in `end()`. */
    public static function start(): self
    {
        $guard = new self(ob_get_level());

        try {
            $guard->buffered = ob_start($guard->filter(...), 1);
        } catch (\Throwable) {
            // no buffer: the build runs without the guard
        }

        return $guard;
    }

    /** Never throws. After it, the guard drops nothing. */
    public function end(): void
    {
        $this->building = false;
        if (!$this->buffered) {
            return;
        }
        for ($open = ob_get_level() - $this->level; $open > 0; --$open) {
            $before = ob_get_level();

            try {
                ob_end_clean();
                // @phpstan-ignore catch.neverThrown (the clean call runs the output handler of the buffer, which is the customer's code)
            } catch (\Throwable) {
                // PHP may have removed the buffer all the same
            }
            if (ob_get_level() >= $before) {
                return; // it cannot be removed, so nothing below it can
            }
        }
    }

    private function filter(string $output): string
    {
        return $this->building ? '' : $output;
    }
}
