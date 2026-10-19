<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

use AgentCat\Internal\Constants;
use Symfony\Component\Process\Process;

/**
 * A container of the runtime tests, and the `docker run` lines that start one (header conflicts 10 and 11, rulings
 * P14 and P15).
 *
 * Every container uses host networking and reaches only 127.0.0.1: its environment must point `AGENTCAT_API_URL` and
 * `AGENTCAT_DIAGNOSTICS_ENDPOINT` there (`RuntimeTestCase::env()`), so a fixture that forgets its collector fails
 * closed, and AgentCat's default hosts resolve to 127.0.0.1 for a runtime that drops that environment. The repository
 * is mounted read-only at its own path and is the working directory; every other mount is the test's output directory
 * or a path in it (`--mount` never creates a host path). PHP CLI images always get `--init`. Every container is named
 * `agentcat-rt-<pid>-<hex>` and runs with `--rm`; `removeAll()` removes the ones that outlive their docker client,
 * `removeStale()` those of a test process that died, and a started container ends on its own after its lifetime.
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

    private const string NAME_PREFIX = 'agentcat-rt-';

    /** The images whose main process is PHP CLI: PHP must not be PID 1 there (header conflict 11). */
    private const array INIT_IMAGES = [Images::CLI, Images::SWOOLE];

    /** The test's output directory is `<test HOME>/runtime-<hex>` (`RuntimeTestCase::out()`). */
    public const string OUTPUT_DIRECTORY_PREFIX = 'runtime-';

    /**
     * Run by `sh -c` in front of a started container's entrypoint (`$1` is the lifetime): after the lifetime it sends
     * SIGTERM to PID 1 (with `--init`, tini passes it on), and 10 s later SIGKILL to every other process.
     */
    private const string LIFETIME_SCRIPT = '(sleep "$1"; kill -TERM 1; sleep 10; kill -KILL -1) >/dev/null 2>&1 & shift; exec "$@"';

    /** @var array<string, list<string>> the entrypoint of each image */
    private static array $entrypoints = [];

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
     * Starts a detached container and waits until `$port` accepts TCP connections on 127.0.0.1. The container ends on
     * its own after `$lifetime` seconds, also when the test process dies before its cleanup.
     *
     * @param non-empty-list<string> $command  the command, after the image's entrypoint
     * @param array<string, string>  $env      the container's environment: `RuntimeTestCase::env()` plus the test's own
     * @param array<string, string>  $mounts   host path (in the output directory) => container path
     * @param float                  $timeout  how long the port may take to accept a connection
     * @param bool                   $remove   false keeps a stopped container for `logs()` until `stop()` (ruling P16)
     * @param float                  $lifetime how long the container may run at most
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
        float $lifetime = 300.0,
    ): self {
        $timeout = self::seconds($timeout);
        $lifetime = (string) (int) ceil(self::seconds($lifetime));
        $runtime = new self(self::newName());
        $options = self::options($image, $runtime->name, $env, $mounts, $init, $remove);
        $run = self::docker(['run', '--detach', ...$options, '--entrypoint', 'sh', $image, '-c', self::LIFETIME_SCRIPT, 'sh', $lifetime, ...self::entrypoint($image), ...$command]);
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
     * @param array<string, string> $mounts host path (in the output directory) => container path
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public static function run(string $image, array $command, array $env, array $mounts): array
    {
        $run = self::docker(['run', ...self::options($image, self::newName(), $env, $mounts, true, true), $image, ...$command], self::RUN_SECONDS);

        return ['exitCode' => $run->getExitCode() ?? -1, 'stdout' => $run->getOutput(), 'stderr' => $run->getErrorOutput()];
    }

    /**
     * The `docker run --interactive` line of a container whose stdin and stdout the caller drives (for
     * `StdioMcpClient::command()`); the caller's process gives it its timeout.
     *
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param array<string, string> $mounts host path (in the output directory) => container path
     *
     * @return list<string>
     */
    public static function command(string $image, array $command, array $env, array $mounts, bool $init = true): array
    {
        return ['docker', 'run', '--interactive', ...self::options($image, self::newName(), $env, $mounts, $init, true), $image, ...$command];
    }

    /**
     * Removes every container of this process (`docker rm --force`), also one whose docker client was killed or timed
     * out: `RuntimeTestCase` calls it after each test and after each class.
     */
    public static function removeAll(): void
    {
        self::remove(self::names(self::namePrefix()));
    }

    /**
     * Removes the containers of runtime test processes that no longer run on this host, for example a PHPUnit run that
     * was killed before its cleanup. `RuntimeTestCase` calls it once per process, before the first container. Names
     * of another form, and those of a live process, stay; without `/proc`, nothing is removed.
     */
    public static function removeStale(): void
    {
        if (!is_dir('/proc/self')) {
            return;
        }
        $stale = [];
        foreach (self::names(self::NAME_PREFIX) as $name) {
            if (preg_match('/\A' . self::NAME_PREFIX . '(\d+)-[0-9a-f]+\z/', $name, $match) === 1 && !is_dir('/proc/' . $match[1])) {
                $stale[] = $name;
            }
        }
        self::remove($stale);
    }

    /**
     * Makes everything under `$dir` writable for everyone, so the test can remove what a container wrote there as
     * root or www-data (ruling P14). The container sees only `$dir`, at `/out`, and no network.
     */
    public static function makeRemovable(string $dir): void
    {
        $name = self::newName();
        $run = self::docker(['run', '--rm', '--pull', 'never', '--name', $name, '--network', 'none', '--mount', self::bind(self::inOutputDirectory($dir), '/out'), Images::CLI, 'chmod', '-R', 'a+rwX', '/out']);
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
     * `docker stop -t`: the image's stop signal (`Images`: SIGQUIT for FPM, SIGWINCH for Apache, SIGTERM for the
     * others; with `--init`, tini passes it on), the runtime's own graceful stop, and SIGKILL after `$timeout` seconds.
     * With `--rm` the container is gone afterwards: read `logs()` first.
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
        self::remove([$this->name]);
    }

    /**
     * The names of all containers, running or not, whose names start with `$prefix`.
     *
     * @return list<string>
     */
    private static function names(string $prefix): array
    {
        $list = self::docker(['ps', '--all', '--format', '{{.Names}}', '--filter', 'name=^/' . $prefix]);
        if (!$list->isSuccessful()) {
            throw new \RuntimeException('docker ps failed: ' . $list->getErrorOutput());
        }
        $names = preg_split('/\s+/', trim($list->getOutput()), -1, PREG_SPLIT_NO_EMPTY);

        return $names === false ? [] : $names;
    }

    /**
     * `docker rm --force`; a container that is already gone is fine.
     *
     * @param list<string> $names
     */
    private static function remove(array $names): void
    {
        if ($names === []) {
            return;
        }
        $remove = self::docker(['rm', '--force', ...$names]);
        if (!$remove->isSuccessful() && !str_contains($remove->getErrorOutput(), 'No such container')) {
            throw new \RuntimeException('docker rm failed: ' . $remove->getErrorOutput());
        }
    }

    /**
     * The image's entrypoint, which `start()` runs after its lifetime script.
     *
     * @return list<string>
     */
    private static function entrypoint(string $image): array
    {
        if (isset(self::$entrypoints[$image])) {
            return self::$entrypoints[$image];
        }
        $inspect = self::docker(['image', 'inspect', '--format', '{{json .Config.Entrypoint}}', $image]);
        if (!$inspect->isSuccessful()) {
            throw new \RuntimeException(sprintf('docker image inspect %s failed: %s', $image, $inspect->getErrorOutput()));
        }
        $decoded = json_decode(trim($inspect->getOutput()), true, 8, JSON_THROW_ON_ERROR);
        $entrypoint = [];
        foreach (is_array($decoded) ? $decoded : [] as $part) {
            if (!is_string($part)) {
                throw new \RuntimeException(sprintf('The entrypoint of %s is not a list of strings: %s', $image, $inspect->getOutput()));
            }
            $entrypoint[] = $part;
        }

        return self::$entrypoints[$image] = $entrypoint;
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
    private static function options(string $image, string $name, array $env, array $mounts, bool $init, bool $remove): array
    {
        foreach (['AGENTCAT_API_URL', 'AGENTCAT_DIAGNOSTICS_ENDPOINT'] as $required) {
            if (!self::local($env[$required] ?? '')) {
                throw new \InvalidArgumentException(sprintf('A container needs %s on http://127.0.0.1:<port>, the collector of the test or a closed port: pass RuntimeTestCase::env()', $required));
            }
        }
        $repo = self::repo();
        $options = ['--pull', 'never', '--name', $name, '--network', 'host', '--mount', self::bind($repo, $repo, readonly: true), '--workdir', $repo];
        foreach ([Constants::DEFAULT_API_BASE_URL, Constants::DEFAULT_DIAGNOSTICS_ENDPOINT] as $default) {
            $options[] = '--add-host';
            $options[] = parse_url($default, PHP_URL_HOST) . ':127.0.0.1';
        }
        if ($remove) {
            $options[] = '--rm';
        }
        if ($init || in_array($image, self::INIT_IMAGES, true)) {
            $options[] = '--init';
        }
        foreach ($mounts as $host => $container) {
            $options[] = '--mount';
            $options[] = self::bind(self::inOutputDirectory($host), $container);
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

    /** An `http://127.0.0.1:<port>` URL, with no user information that would name another host. */
    private static function local(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && str_starts_with($url, self::FAIL_CLOSED_PREFIX) && ($parts['scheme'] ?? null) === 'http'
            && ($parts['host'] ?? null) === '127.0.0.1' && isset($parts['port']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * The real path of `$host`, which must be the test's output directory (`<test HOME>/runtime-<hex>`) or a path in
     * it: the only host place a container may write, and the one the cleanup makes removable.
     */
    private static function inOutputDirectory(string $host): string
    {
        $home = getenv('HOME');
        $realHome = is_string($home) ? realpath($home) : false;
        $real = realpath($host);
        if ($realHome === false || $real === false || !str_starts_with($real, $realHome . '/' . self::OUTPUT_DIRECTORY_PREFIX)) {
            throw new \InvalidArgumentException(sprintf('A mount source must be the test\'s output directory (RuntimeTestCase::out()) or a path in it: %s', $host));
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

        return self::NAME_PREFIX . ($pid === false ? 0 : $pid) . '-';
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
