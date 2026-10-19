<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

use Symfony\Component\Process\Process;

/**
 * A container of the runtime tests, and the `docker run` lines that start one (header conflicts 10 and 11, rulings
 * P14 and P15).
 *
 * Every container uses host networking and reaches only 127.0.0.1: its environment must point `AGENTCAT_API_URL` and
 * `AGENTCAT_DIAGNOSTICS_ENDPOINT` there (`RuntimeTestCase::env()`), so a fixture that forgets its collector fails
 * closed. The repository is mounted read-only at its own path and is the working directory; every other mount is an
 * existing path under the test HOME (`--mount` never creates a host path). Every container is named
 * `agentcat-rt-<pid>-<hex>` and runs with `--rm`; `removeAll()` removes the ones that outlive their docker client.
 * Every docker command runs with a timeout.
 */
final class DockerRuntime
{
    /** How long one docker command may take: the docker client, not the work of a detached container. */
    private const float DOCKER_SECONDS = 30.0;

    /** How long a container of `run()` may run. */
    private const float RUN_SECONDS = 60.0;

    /** The longest timeout a caller may give. */
    private const float MAX_SECONDS = 600.0;

    /** How long one connection attempt to the port of a starting container waits. */
    private const float CONNECT_SECONDS = 0.5;

    /** How often a start checks that the container still runs. */
    private const float RUNNING_CHECK_SECONDS = 1.0;

    private const int POLL_MICROSECONDS = 50_000;

    private const string FAIL_CLOSED_PREFIX = 'http://127.0.0.1:';

    private static bool $checked = false;

    private static ?string $problem = null;

    private function __construct(private readonly string $name) {}

    /** `AGENTCAT_RUNTIME_TESTS=1`: the runtime tests run, and a Docker that does not answer fails them. */
    public static function requested(): bool
    {
        return getenv('AGENTCAT_RUNTIME_TESTS') === '1';
    }

    /** Why `docker info` failed or timed out, with `DOCKER_HOST`; null when it exits 0 (checked once per process). */
    public static function problem(): ?string
    {
        if (self::$checked) {
            return self::$problem;
        }
        self::$checked = true;

        try {
            $info = self::docker(['info']);
            $error = $info->isSuccessful() ? null : sprintf('exit %s: %s', var_export($info->getExitCode(), true), trim($info->getErrorOutput()) !== '' ? $info->getErrorOutput() : $info->getOutput());
        } catch (\RuntimeException $e) {
            $error = $e->getMessage(); // the docker client is missing or timed out
        }
        $host = getenv('DOCKER_HOST');

        return self::$problem = $error === null ? null : sprintf('docker info failed (DOCKER_HOST=%s): %s', is_string($host) ? $host : 'unset', trim($error));
    }

    /** `requested()`, and `docker info` exits 0. */
    public static function available(): bool
    {
        return self::requested() && self::problem() === null;
    }

    /** The repository's absolute path, which every container mounts read-only at the same path. */
    public static function repo(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Starts a detached container and waits until `$port` accepts TCP connections on 127.0.0.1.
     *
     * @param list<string>          $command the command, after the image's entrypoint
     * @param array<string, string> $env     the container's environment: `RuntimeTestCase::env()` plus the test's own
     * @param array<string, string> $mounts  host path (under the test HOME) => container path
     * @param float                 $timeout how long the port may take to accept a connection
     * @param bool                  $remove  false keeps a stopped container for `logs()` until `stop()` (ruling P16)
     */
    public static function start(
        string $image,
        array $command,
        array $env,
        array $mounts,
        ?int $port = null,
        bool $init = false,
        float $timeout = 30.0,
        bool $remove = true,
    ): self {
        $timeout = self::seconds($timeout);
        $runtime = new self(self::newName());
        $run = self::docker(['run', '--detach', ...self::options($runtime->name, $env, $mounts, $init, $remove), $image, ...$command]);
        if (!$run->isSuccessful()) {
            throw new \RuntimeException(sprintf('docker run %s failed: %s', $image, $run->getErrorOutput()));
        }
        if ($port !== null) {
            $runtime->waitForPort($port, $timeout);
        }

        return $runtime;
    }

    /**
     * Runs a container to its end (with `--init`) and returns what it wrote.
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param array<string, string> $mounts host path (under the test HOME) => container path
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(string $image, array $command, array $env, array $mounts): array
    {
        $run = self::docker(['run', ...self::options(self::newName(), $env, $mounts, true, true), $image, ...$command], self::RUN_SECONDS);

        return ['exitCode' => $run->getExitCode() ?? -1, 'stdout' => $run->getOutput(), 'stderr' => $run->getErrorOutput()];
    }

    /**
     * The `docker run --interactive` line of a container whose stdin and stdout the caller drives (for
     * `StdioMcpClient::command()`); the caller's process gives it its timeout.
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param array<string, string> $mounts host path (under the test HOME) => container path
     *
     * @return list<string>
     */
    public static function command(string $image, array $command, array $env, array $mounts, bool $init = true): array
    {
        return ['docker', 'run', '--interactive', ...self::options(self::newName(), $env, $mounts, $init, true), $image, ...$command];
    }

    /**
     * Removes every container of this process (`docker rm --force`), also one whose docker client was killed or timed
     * out: `RuntimeTestCase` calls it after each test and after each class.
     */
    public static function removeAll(): void
    {
        $list = self::docker(['ps', '--all', '--quiet', '--filter', 'name=^/' . self::namePrefix()]);
        if (!$list->isSuccessful()) {
            throw new \RuntimeException('docker ps failed: ' . $list->getErrorOutput());
        }
        $ids = preg_split('/\s+/', trim($list->getOutput()), -1, PREG_SPLIT_NO_EMPTY);
        if ($ids === false || $ids === []) {
            return;
        }
        $remove = self::docker(['rm', '--force', ...$ids]);
        if (!$remove->isSuccessful() && !str_contains($remove->getErrorOutput(), 'No such container')) {
            throw new \RuntimeException('docker rm failed: ' . $remove->getErrorOutput());
        }
    }

    /**
     * Makes everything under `$dir` writable for everyone, so the test can remove what a container wrote there as
     * root or www-data (ruling P14). The container sees only `$dir`, at `/out`, and no network.
     */
    public static function makeRemovable(string $dir): void
    {
        $name = self::newName();
        $run = self::docker(['run', '--rm', '--pull', 'never', '--name', $name, '--network', 'none', '--mount', self::bind(self::underTestHome($dir), '/out'), Images::CLI, 'chmod', '-R', 'a+rwX', '/out']);
        if (!$run->isSuccessful()) {
            throw new \RuntimeException(sprintf('chmod of %s in %s failed: %s', $dir, Images::CLI, $run->getErrorOutput()));
        }
    }

    /** What the container wrote to stdout and stderr so far. With `--rm`, read it before `stopGracefully()`. */
    public function logs(): string
    {
        $logs = self::docker(['logs', $this->name]);
        if (!$logs->isSuccessful()) {
            throw new \RuntimeException(sprintf('docker logs %s failed: %s', $this->name, $logs->getErrorOutput()));
        }

        return $logs->getOutput() . $logs->getErrorOutput();
    }

    /**
     * `docker stop -t`: SIGTERM, the runtime's own graceful stop, and SIGKILL after `$timeout` seconds. With `--rm`
     * the container is gone afterwards: read `logs()` first.
     */
    public function stopGracefully(float $timeout): void
    {
        $seconds = (int) ceil(self::seconds($timeout));
        $stop = self::docker(['stop', '-t', (string) $seconds, $this->name], $seconds + self::DOCKER_SECONDS);
        if (!$stop->isSuccessful()) {
            throw new \RuntimeException(sprintf('docker stop %s failed: %s', $this->name, $stop->getErrorOutput()));
        }
    }

    /** `docker rm --force`; a container that is already gone is fine. */
    public function stop(): void
    {
        $remove = self::docker(['rm', '--force', $this->name]);
        if (!$remove->isSuccessful() && !str_contains($remove->getErrorOutput(), 'No such container')) {
            throw new \RuntimeException(sprintf('docker rm %s failed: %s', $this->name, $remove->getErrorOutput()));
        }
    }

    private function waitForPort(int $port, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        $nextRunningCheck = microtime(true) + self::RUNNING_CHECK_SECONDS;
        while (!$this->accepts($port)) {
            if (microtime(true) >= $nextRunningCheck) {
                if (!$this->running()) {
                    throw new \RuntimeException(sprintf('Container %s ended before port %d accepted a connection: %s', $this->name, $port, $this->logsOrWhy()));
                }
                $nextRunningCheck = microtime(true) + self::RUNNING_CHECK_SECONDS;
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(sprintf('Port %d of container %s accepted no connection within %.1f s: %s', $port, $this->name, $timeout, $this->logsOrWhy()));
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private function accepts(int $port): bool
    {
        set_error_handler(static fn(): bool => true); // connection refused while the runtime boots

        try {
            $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, self::CONNECT_SECONDS);
        } finally {
            restore_error_handler();
        }
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function running(): bool
    {
        $inspect = self::docker(['inspect', '--format', '{{.State.Running}}', $this->name]);

        return $inspect->isSuccessful() && trim($inspect->getOutput()) === 'true';
    }

    private function logsOrWhy(): string
    {
        try {
            return $this->logs();
        } catch (\RuntimeException $e) {
            return $e->getMessage() . ' (a container with --rm is removed when it ends: start it with remove: false to keep its logs)';
        }
    }

    /**
     * The options every container gets.
     *
     * @param array<string, string> $env
     * @param array<string, string> $mounts
     *
     * @return list<string>
     */
    private static function options(string $name, array $env, array $mounts, bool $init, bool $remove): array
    {
        foreach (['AGENTCAT_API_URL', 'AGENTCAT_DIAGNOSTICS_ENDPOINT'] as $required) {
            if (!str_starts_with($env[$required] ?? '', self::FAIL_CLOSED_PREFIX)) {
                throw new \InvalidArgumentException(sprintf('A container needs %s on 127.0.0.1, the collector of the test or a closed port: pass RuntimeTestCase::env()', $required));
            }
        }
        $repo = self::repo();
        $options = ['--pull', 'never', '--name', $name, '--network', 'host', '--mount', self::bind($repo, $repo, readonly: true), '--workdir', $repo];
        if ($remove) {
            $options[] = '--rm';
        }
        if ($init) {
            $options[] = '--init';
        }
        foreach ($mounts as $host => $container) {
            $options[] = '--mount';
            $options[] = self::bind(self::underTestHome($host), $container);
        }
        foreach ($env as $variable => $value) {
            $options[] = '--env';
            $options[] = $variable . '=' . $value;
        }

        return $options;
    }

    /** A `--mount` value: an existing host path, never created by docker. */
    private static function bind(string $host, string $container, bool $readonly = false): string
    {
        foreach ([$host, $container] as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, ',')) {
                throw new \InvalidArgumentException('Not an absolute path without commas: ' . $path);
            }
        }

        return sprintf('type=bind,source=%s,target=%s%s', $host, $container, $readonly ? ',readonly' : '');
    }

    /** The real path of `$host`, which must exist under the test HOME: the only host paths a container may write. */
    private static function underTestHome(string $host): string
    {
        $home = getenv('HOME');
        $realHome = is_string($home) ? realpath($home) : false;
        $real = realpath($host);
        if ($realHome === false || $real === false || !str_starts_with($real, $realHome . '/')) {
            throw new \InvalidArgumentException(sprintf('A mount source must exist under the test HOME (%s): %s', var_export($home, true), $host));
        }

        return $real;
    }

    /** @param list<string> $arguments */
    private static function docker(array $arguments, float $timeout = self::DOCKER_SECONDS): Process
    {
        $process = new Process(['docker', ...$arguments], null, null, null, $timeout);
        $process->run();

        return $process;
    }

    private static function newName(): string
    {
        return self::namePrefix() . bin2hex(random_bytes(4));
    }

    private static function namePrefix(): string
    {
        $pid = getmypid();

        return 'agentcat-rt-' . ($pid === false ? 0 : $pid) . '-';
    }

    /** A timeout the docker commands and the deadlines can use: finite, positive and at most `MAX_SECONDS`. */
    private static function seconds(float $seconds): float
    {
        if (!is_finite($seconds) || $seconds <= 0.0 || $seconds > self::MAX_SECONDS) {
            throw new \InvalidArgumentException(sprintf('A timeout must be above 0 and at most %d s: %s', (int) self::MAX_SECONDS, var_export($seconds, true)));
        }

        return $seconds;
    }
}
