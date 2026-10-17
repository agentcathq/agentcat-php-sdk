<?php

declare(strict_types=1);

namespace AgentCat\Internal\Delivery;

/**
 * Delivers the global queue when a long-running CLI server (an MCP stdio server) gets SIGTERM or SIGINT. Without
 * a handler, these signals end PHP at once: no shutdown function runs, and the waiting events are lost.
 *
 * The global queue calls `install()` when it gets an event, so nothing happens at autoload. It installs once, only
 * under the CLI SAPI with ext-pcntl, and keeps the handler each signal had before. On the signal it shuts the queue down
 * (`EventQueue::SHUTDOWN_TIMEOUT_SECONDS` at most). Then it calls that earlier handler, and the process keeps
 * running, or, when there was none, it raises the signal again with the default disposition (TypeScript
 * `runSignalShutdown`). A signal that the application ignores (`SIG_IGN`) stays ignored.
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

    /** Installs the handlers once. Does nothing outside the CLI SAPI or without ext-pcntl. */
    public function install(): void
    {
        if ($this->installed || $this->sapi !== 'cli') {
            return;
        }
        foreach (['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'] as $function) {
            if (!($this->functionExists)($function)) {
                return;
            }
        }
        $this->installed = true;
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            $previous = pcntl_signal_get_handler($signal);
            if ($previous === \SIG_IGN) {
                continue; // the application ignores this signal: so do we
            }
            pcntl_signal($signal, function (int $signo) use ($previous): void {
                $this->onSignal($signo, $previous);
            });
        }
        pcntl_async_signals(true); // the stdio loop sleeps in usleep(): handlers must run without pcntl_signal_dispatch()
    }

    /**
     * Shuts the queue down, then calls the earlier handler (`03` AC-11: the process keeps running) or raises the
     * signal again (`03` AC-10).
     *
     * @param mixed $previous the handler before ours: a callable, or `SIG_DFL`
     */
    public function onSignal(int $signal, mixed $previous): void
    {
        try {
            ($this->queue)()->finalShutdown(); // at most EventQueue::SHUTDOWN_TIMEOUT_SECONDS
        } catch (\Throwable) {
            // a signal handler must never throw
        }
        if (is_callable($previous)) {
            $previous($signal, null);

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
