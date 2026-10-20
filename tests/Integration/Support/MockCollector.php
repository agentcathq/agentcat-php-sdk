<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Support;

use AgentCat\Internal\Json\Json;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * A local mock of the AgentCat collector: `php -S 127.0.0.1:<free port>` serving `router.php`.
 *
 * Test classes start one in `setUpBeforeClass()` and stop it in `tearDownAfterClass()`. The server runs under
 * `watchdog.php`, which stops it when this process closes its stdin: on `stop()`, and also when the test process
 * dies without running any cleanup.
 */
final class MockCollector
{
    private const float START_TIMEOUT_SECONDS = 5.0;

    private const float STOP_TIMEOUT_SECONDS = 1.0;

    /** A new port is tried when the server exits at once (another process took the free port first). */
    private const int START_ATTEMPTS = 3;

    /** How often `waitFor()` reads the requests again. */
    private const int POLL_MICROSECONDS = 20_000;

    /** The SDK's line for an event that the AgentCat API accepted, as diagnostics posts and AgentCat's log carry it. */
    public const string SENT_EVENT = 'Successfully sent event ';

    private bool $stopped = false;

    private function __construct(
        private readonly Process $process,
        private readonly InputStream $input,
        private readonly string $dir,
        private readonly int $port,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    public static function start(): self
    {
        $errors = '';
        for ($attempt = 1; $attempt <= self::START_ATTEMPTS; ++$attempt) {
            $collector = self::launch();
            if ($collector->waitUntilListening()) {
                return $collector;
            }
            $exitedAtOnce = !$collector->process->isRunning();
            $errors .= $collector->process->getErrorOutput() . $collector->process->getOutput();
            $collector->stop();
            if (!$exitedAtOnce) {
                break;
            }
        }

        throw new \RuntimeException('The mock collector did not start: ' . $errors);
    }

    /**
     * A new scenario; returns its base URL (`http://127.0.0.1:<port>/c/<id>`).
     *
     * @param list<array{status: int, delayMs?: int, body?: string, location?: string}> $steps   responses to requests 0, 1, 2, …;
     *                                                                                  `location` is sent as the Location header
     * @param array{status: int, delayMs?: int, body?: string, location?: string}       $default the response once the steps run out
     */
    public function scenario(array $steps = [], array $default = ['status' => 202, 'body' => '{"status":"accepted"}']): string
    {
        $id = bin2hex(random_bytes(8));
        file_put_contents(
            $this->dir . '/' . $id . '.json',
            json_encode(['steps' => $steps, 'default' => $default], JSON_THROW_ON_ERROR),
        );

        return 'http://127.0.0.1:' . $this->port . '/c/' . $id;
    }

    /**
     * The requests a scenario received so far, in arrival order (`n`).
     *
     * @return list<\stdClass> each with `n`, `method`, `path`, `headers`, `body`, `start` and `end`
     *
     * @phpstan-impure the collector writes the log while the test runs
     */
    public function requests(string $baseUrl): array
    {
        if (preg_match('#/c/([a-z0-9]+)\z#', $baseUrl, $match) !== 1) {
            throw new \InvalidArgumentException('Not a scenario URL: ' . $baseUrl);
        }
        $path = $this->dir . '/' . $match[1] . '.log';
        if (!is_file($path)) {
            return [];
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read ' . $path);
        }

        try {
            flock($handle, LOCK_SH); // the router appends with LOCK_EX
            $contents = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $contents = trim($contents);
        if ($contents === '') {
            return []; // file_put_contents() creates the log before it takes its lock: the first record is not written yet
        }
        $requests = [];
        foreach (explode("\n", $contents) as $line) {
            $request = Json::decode($line);
            if (!$request instanceof \stdClass) {
                throw new \UnexpectedValueException('Not a request record: ' . $line);
            }
            $requests[] = $request;
        }
        usort($requests, static fn(\stdClass $a, \stdClass $b): int => self::number($a, 'n') <=> self::number($b, 'n'));

        return $requests;
    }

    /**
     * Reads the requests of a scenario again until `$done` accepts them or `$timeout` seconds pass, and returns the last
     * read. The caller asserts what it waited for.
     *
     * @param \Closure(list<\stdClass>): bool $done
     *
     * @return list<\stdClass> in arrival order, as `requests()` returns them
     *
     * @phpstan-impure the collector writes the log while the test runs
     */
    public function waitFor(string $baseUrl, \Closure $done, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $requests = $this->requests($baseUrl);
        while (!$done($requests) && microtime(true) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
            $requests = $this->requests($baseUrl);
        }

        return $requests;
    }

    /**
     * The diagnostics posts among `$requests` that carry a `Successfully sent event` line: of any event, or of the event
     * `$eventId` (`evt_…`).
     *
     * @param list<\stdClass> $requests
     *
     * @return list<\stdClass>
     */
    public static function withASentEvent(array $requests, string $eventId = ''): array
    {
        return array_values(array_filter($requests, static fn(\stdClass $request): bool => str_contains(self::body($request), self::SENT_EVENT . $eventId)));
    }

    /** The body of a recorded request; the test fails when the record has none. */
    public static function body(\stdClass $request): string
    {
        $body = $request->body ?? null;
        Assert::assertIsString($body);

        return $body;
    }

    /**
     * The largest number of requests the server handled at the same time.
     *
     * @param list<\stdClass> $requests
     */
    public static function maxConcurrency(array $requests): int
    {
        $points = [];
        foreach ($requests as $request) {
            $points[] = [self::number($request, 'start'), 1];
            $points[] = [self::number($request, 'end'), -1];
        }
        // At equal times an end (-1) sorts before a start (+1): touching intervals do not overlap.
        usort($points, static fn(array $a, array $b): int => $a <=> $b);
        $current = 0;
        $max = 0;
        foreach ($points as [, $delta]) {
            $current += $delta;
            $max = max($max, $current);
        }

        return $max;
    }

    /** A URL where nothing listens (connection refused). */
    public static function closedPortUrl(): string
    {
        return 'http://127.0.0.1:' . self::freePort();
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->stopProcess();
        $this->removeDir($this->dir);
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

    private static function launch(): self
    {
        $dir = sys_get_temp_dir() . '/agentcat-collector-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0o700)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $port = self::freePort();
        $input = new InputStream();
        $process = new Process(
            [PHP_BINARY, __DIR__ . '/watchdog.php', PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
            null,
            ['PHP_CLI_SERVER_WORKERS' => '8', 'AGENTCAT_COLLECTOR_DIR' => $dir, 'HOME' => $dir],
            $input,
            null,
        );
        $process->start();

        return new self($process, $input, $dir, $port);
    }

    /** Closing stdin makes the watchdog stop the server; a kill is the last resort. */
    private function stopProcess(): void
    {
        $this->input->close();
        $deadline = microtime(true) + self::STOP_TIMEOUT_SECONDS;
        while ($this->process->isRunning() && microtime(true) < $deadline) {
            usleep(10_000);
        }
        $this->process->stop(0);
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

    private static function number(\stdClass $request, string $field): float
    {
        $value = $request->{$field} ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new \UnexpectedValueException('Request record without a numeric ' . $field);
        }

        return (float) $value;
    }

    /** The watchdog deletes the directory when it stops the server; this covers a watchdog that had to be killed. */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $names = scandir($dir);
        foreach ($names === false ? [] : $names as $name) {
            if (is_file($dir . '/' . $name)) {
                unlink($dir . '/' . $name);
            }
        }
        rmdir($dir);
    }
}
