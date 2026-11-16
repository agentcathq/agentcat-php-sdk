<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * Keeps what the customer's code prints during a heartbeat build out of the output of the process: an output buffer
 * whose handler discards every write at once (chunk size 1), so nothing waits in it. `end()` removes the buffer and
 * every buffer that the code left open above it, with one pass for each, so a buffer that PHP cannot remove ends the
 * loop instead of holding it. Such a buffer stays, with this one below it: the handler then discards the bytes that
 * waited in it when the build ended, and passes on everything after them. Call it under an error handler that takes
 * PHP's notices: a buffer that cannot be removed raises one.
 *
 * @internal
 */
final class OutputGuard
{
    /** True while the build runs: every write is discarded. */
    private bool $discardAll = true;

    /** After the build: how many of the next bytes are what a buffer above this one held when the build ended. */
    private int $discardBytes = 0;

    private bool $buffered = false;

    /** @param \Closure(): bool $remove */
    private function __construct(private readonly int $level, private readonly \Closure $remove) {}

    /** @param (\Closure(): bool)|null $remove tests only: removes the buffer on top, or fails to; default `ob_end_clean()` */
    public static function start(?\Closure $remove = null): self
    {
        $guard = new self(ob_get_level(), $remove ?? ob_end_clean(...));
        $guard->buffered = ob_start($guard->filter(...), 1);

        return $guard;
    }

    public function end(): void
    {
        if (!$this->buffered) {
            return;
        }
        for ($open = ob_get_level() - $this->level; $open > 0 && ob_get_level() > $this->level; --$open) {
            ($this->remove)();
        }
        foreach (array_slice(ob_get_status(true), $this->level + 1) as $above) {
            $used = is_array($above) ? $above['buffer_used'] ?? null : null;
            $this->discardBytes += is_int($used) ? $used : 0;
        }
        $this->discardAll = false;
    }

    private function filter(string $output): string
    {
        if ($this->discardAll) {
            return '';
        }
        $cut = min($this->discardBytes, strlen($output));
        $this->discardBytes -= $cut;

        return substr($output, $cut);
    }
}
