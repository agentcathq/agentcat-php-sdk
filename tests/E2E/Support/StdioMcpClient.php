<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Support;

use AgentCat\Internal\Json\Json;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * A raw JSON-RPC client for an MCP stdio server: `php <script>` with pipes, one JSON message per line on stdin, every
 * stdout line kept for the test.
 *
 * The server has a 10 s timeout, checked whenever this client reads, so a hang fails the test instead of blocking it.
 * The server also ends when this process dies: its stdin then reaches EOF.
 */
final class StdioMcpClient
{
    private const float TIMEOUT_SECONDS = 10.0;

    /** How long one poll of the pipes waits before the next one. */
    private const int POLL_MICROSECONDS = 2_000;

    /** @var list<string> complete stdout lines, without their line feed */
    private array $lines = [];

    /** stdout bytes after the last line feed */
    private string $partial = '';

    private function __construct(
        private readonly Process $process,
        private readonly InputStream $input,
    ) {}

    /**
     * Starts the server.
     *
     * @param string $script the server script
     * @param array<string, string> $env environment variables of the server, on top of this process's
     */
    public static function start(string $script, array $env): self
    {
        $input = new InputStream();
        $process = new Process([PHP_BINARY, $script], null, $env, $input, self::TIMEOUT_SECONDS);
        $process->start();

        return new self($process, $input);
    }

    /**
     * Writes one message as one line on the server's stdin.
     *
     * @param array<string, mixed> $message
     */
    public function send(array $message): void
    {
        $this->input->write(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $this->read();
    }

    /** Reads stdout until the answer (`result` or `error`) to request `$id` arrives; fails the test after `$timeout`. */
    public function receive(int|string $id, float $timeout = 5.0): \stdClass
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $running = $this->process->isRunning(); // before the read: an ended server has nothing more to say
            $this->read();
            foreach ($this->lines as $line) {
                $message = $this->decode($line);
                if ($message instanceof \stdClass && ($message->id ?? null) === $id
                    && (property_exists($message, 'result') || property_exists($message, 'error'))) {
                    return $message;
                }
            }
            if (!$running || microtime(true) >= $deadline) {
                Assert::fail(sprintf(
                    'No answer to request %s within %.1f s. stdout: %s stderr: %s',
                    var_export($id, true),
                    $timeout,
                    implode("\n", $this->stdoutLines()),
                    $this->process->getErrorOutput(),
                ));
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Every stdout line read so far, without its line feed; bytes after the last line feed come last.
     *
     * @return list<string>
     */
    public function stdoutLines(): array
    {
        return $this->partial === '' ? $this->lines : [...$this->lines, $this->partial];
    }

    /** Closes the server's stdin: the server reads EOF. */
    public function closeInput(): void
    {
        $this->input->close();
        $this->read();
    }

    /** Waits up to `$timeout` for the server to end; returns its exit code (128 + n after signal n), or null. */
    public function waitForExit(float $timeout): ?int
    {
        $deadline = microtime(true) + $timeout;
        while ($this->process->isRunning()) {
            $this->read();
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(self::POLL_MICROSECONDS);
        }
        $this->read();

        return $this->process->getExitCode();
    }

    public function signal(int $signal): void
    {
        $this->process->signal($signal);
    }

    public function process(): Process
    {
        return $this->process;
    }

    /** Moves new stdout bytes into lines, writes pending stdin bytes, and stops the server after its timeout. */
    private function read(): void
    {
        if ($this->process->isRunning()) {
            $this->process->checkTimeout();
        }
        $chunk = $this->process->getIncrementalOutput();
        if ($chunk === '') {
            return;
        }
        $parts = explode("\n", $this->partial . $chunk);
        $this->partial = array_pop($parts);
        array_push($this->lines, ...$parts);
    }

    private function decode(string $line): mixed
    {
        try {
            return Json::decode($line);
        } catch (\JsonException) {
            return null; // not JSON: the test's stdout check reports it
        }
    }
}
