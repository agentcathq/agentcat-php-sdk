<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * Delivers the global queue when a long-running CLI server (an MCP stdio server) gets SIGTERM or SIGINT. Without
 * a handler, these signals end PHP at once: no shutdown function runs, and the waiting events are lost.
 *
 * The global queue calls `install()` when it gets an event, so nothing happens at autoload. It installs once, only
 * under the CLI SAPI with ext-pcntl, and keeps the handler each signal had before. A signal that the application
 * ignores in PHP (`pcntl_signal(…, SIG_IGN)`) stays ignored; an ignore inherited from the parent process is not
 * visible to PHP, so AgentCat installs its drain for that signal. When it installs a handler, `install()` also turns
 * on `pcntl_async_signals(true)`, which is process wide: from then on every PHP signal handler of the process runs
 * between two opcodes, not at `pcntl_signal_dispatch()` or a tick.
 *
 * Each handler runs once (TypeScript `process.once`). It first gives the signal back to the earlier handler, so a
 * second Ctrl-C during the drain acts as if AgentCat were not there. Then it shuts the queue down
 * (`EventQueue::SHUTDOWN_TIMEOUT_SECONDS` at most, shared with the shutdown function), then calls that earlier
 * handler with PHP's arguments, and the process keeps running, or, when there was none, it raises the signal again
 * with the default disposition (TypeScript `runSignalShutdown`).
 *
 * @internal
 */
final class SignalDrain
{
    /** @var \Closure(int): void */
    private readonly \Closure $raise;

    /** @var \Closure(string): bool */
    private readonly \Closure $functionExists;

    private bool $installed = false;

    private bool $disabled = false;

    /**
     * @param \Closure(): EventQueue        $queue          the queue to deliver, looked up at the signal
     * @param (\Closure(int): void)|null    $raise          raises the signal with the default disposition: default
     *                                                      `pcntl_signal($signal, SIG_DFL)`, then `posix_kill()` to this
     *                                                      process, or `exit(128 + $signal)` without ext-posix
     * @param (\Closure(string): bool)|null $functionExists default `function_exists(...)`
     */
    public function __construct(
        private readonly \Closure $queue,
        ?\Closure $raise = null,
        ?\Closure $functionExists = null,
        private readonly string $sapi = PHP_SAPI,
    ) {
        $this->raise = $raise ?? $this->raiseWithDefaultDisposition(...);
        $this->functionExists = $functionExists ?? function_exists(...);
    }

    /** Never installs after this. False when the handlers are installed already (then nothing changes). */
    public function disable(): bool
    {
        if ($this->installed) {
            return false;
        }
        $this->disabled = true;

        return true;
    }

    /** Installs the handlers once. Does nothing outside the CLI SAPI or without ext-pcntl. */
    public function install(): void
    {
        if ($this->installed || $this->disabled || $this->sapi !== 'cli') {
            return;
        }
        foreach (['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'] as $function) {
            if (!($this->functionExists)($function)) {
                return;
            }
        }
        $this->installed = true;
        $installedAny = false;
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            $previous = pcntl_signal_get_handler($signal);
            if ($previous === \SIG_IGN) {
                continue; // the application ignores this signal: so do we
            }
            pcntl_signal($signal, function (int $signo, mixed $siginfo = null) use ($previous): void {
                pcntl_signal($signo, $previous); // once: a second signal during the drain goes where it went before
                $this->onSignal($signo, $previous, $siginfo);
            });
            $installedAny = true;
        }
        if ($installedAny) {
            pcntl_async_signals(true); // the stdio loop sleeps in usleep(): handlers must run without pcntl_signal_dispatch()
        }
    }

    /**
     * Shuts the queue down, then calls the earlier handler (`03` AC-11: the process keeps running) or raises the
     * signal again (`03` AC-10).
     *
     * @param mixed $previous the handler before ours: a callable, or `SIG_DFL`
     * @param mixed $siginfo  PHP's second handler argument (an array), passed on to `$previous` unchanged
     */
    public function onSignal(int $signal, mixed $previous, mixed $siginfo = null): void
    {
        try {
            ($this->queue)()->finalShutdown(); // at most what is left of EventQueue::SHUTDOWN_TIMEOUT_SECONDS
        } catch (\Throwable) {
            // a signal handler must never throw
        }
        if (is_callable($previous)) {
            $previous($signal, $siginfo);

            return;
        }
        ($this->raise)($signal);
    }

    private function raiseWithDefaultDisposition(int $signal): void
    {
        pcntl_signal($signal, \SIG_DFL);
        if (($this->functionExists)('posix_kill')) {
            posix_kill(posix_getpid(), $signal); // the default disposition of SIGTERM and SIGINT ends the process here

            return;
        }
        exit(128 + $signal); // the shell's exit status for "ended by this signal"
    }
}
