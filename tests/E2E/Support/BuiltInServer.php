<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Support;

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * `php -S 127.0.0.1:<free port> <router>` with 4 workers: an HTTP server whose every request is a fresh PHP request,
 * like PHP-FPM.
 *
 * Test classes start one in `setUpBeforeClass()` and stop it in `tearDownAfterClass()`. Like `MockCollector`, the
 * server runs under `tests/Integration/Support/watchdog.php`, which stops it when this process closes its stdin, also
 * when the test process dies without running any cleanup, and in any case after 120 s.
 */
final class BuiltInServer
{
    private const float START_TIMEOUT_SECONDS = 5.0;

    private const float STOP_TIMEOUT_SECONDS = 1.0;

    /** Longer than any test class: the watchdog stops the server after that, even when this process hangs. */
    private const int LIFETIME_SECONDS = 120;

    private const int SIGKILL = 9;

    /** A new port is tried when the server exits at once (another process took the free port first). */
    private const int START_ATTEMPTS = 3;

    private bool $stopped = false;

    private function __construct(
        private readonly Process $process,
        private readonly InputStream $input,
        private readonly int $port,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Starts the server and waits until it listens.
     *
     * @param string $router the router script that answers every request
     * @param array<string, string> $env environment variables of the server, on top of this process's
     * @param array<string, string> $ini php.ini settings of the server (`-d`)
     */
    public static function start(string $router, array $env, array $ini = ['output_buffering' => '0']): self
    {
        $errors = '';
        for ($attempt = 1; $attempt <= self::START_ATTEMPTS; ++$attempt) {
            $server = self::launch($router, $env, $ini);
            if ($server->waitUntilListening()) {
                return $server;
            }
            $exitedAtOnce = !$server->process->isRunning();
            $errors .= $server->process->getErrorOutput() . $server->process->getOutput();
            $server->stop();
            if (!$exitedAtOnce) {
                break;
            }
        }

        throw new \RuntimeException('The built-in server did not start: ' . $errors);
    }

    /** `http://127.0.0.1:<port>`, without a trailing slash. */
    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * Kills the watchdog's process group: the watchdog, `php -S` and its workers, also a worker that ignores SIGTERM
     * or hangs. The watchdog still runs at that moment, so the group ID is still its own. Without ext-posix, closing
     * stdin makes the watchdog stop the server, and a kill of the watchdog is the last resort.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $pid = $this->process->getPid();
        if ($pid !== null && $this->process->isRunning() && function_exists('posix_kill')) {
            posix_kill(-$pid, self::SIGKILL);
        }
        $this->input->close();
        $deadline = microtime(true) + self::STOP_TIMEOUT_SECONDS;
        while ($this->process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->process->stop(0);
    }

    /**
     * @param array<string, string> $env
     * @param array<string, string> $ini
     */
    private static function launch(string $router, array $env, array $ini): self
    {
        $port = self::freePort();
        $input = new InputStream();
        // The default is output_buffering=0. php.ini-production sets output_buffering=4096, and PHP empties that output
        // buffer only after the shutdown functions. Without it, the router's flush() sends the body before the shutdown
        // functions run; BufferedOutputTest keeps 4096 to show that AgentCat sends the buffer before it delivers.
        $settings = [];
        foreach ($ini as $name => $value) {
            $settings[] = '-d';
            $settings[] = $name . '=' . $value;
        }
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/../../Integration/Support/watchdog.php', PHP_BINARY, ...$settings, '-S', '127.0.0.1:' . $port, $router],
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4', 'AGENTCAT_WATCHDOG_TIMEOUT' => (string) self::LIFETIME_SECONDS, ...$env],
            $input,
            self::LIFETIME_SECONDS,
        );
        $process->start();

        return new self($process, $input, $port);
    }

    private function waitUntilListening(): bool
    {
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        $banner = '(http://127.0.0.1:' . $this->port . ') started';
        set_error_handler(static fn(): bool => true); // connection refused warnings while the server boots

        try {
            while ($this->process->isRunning()) {
                // The banner proves that this server, not another process, owns the port.
                if (str_contains($this->process->getErrorOutput() . $this->process->getOutput(), $banner)) {
                    $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errorCode, $errorMessage, 1.0);
                    if ($socket !== false) {
                        fclose($socket);

                        return true;
                    }
                }
                if (microtime(true) >= $deadline) {
                    return false;
                }
                usleep(10_000);
            }

            return false;
        } finally {
            restore_error_handler();
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($server === false) {
            throw new \RuntimeException('No free port: ' . $errorMessage);
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if ($name === false || preg_match('/:(\d+)\z/', $name, $match) !== 1) {
            throw new \RuntimeException('No free port');
        }

        return (int) $match[1];
    }
}
