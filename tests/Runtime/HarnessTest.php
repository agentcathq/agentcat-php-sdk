<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\Thrown;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/** The harness of the runtime tests: what it does when Docker fails, and how it cleans up. */
#[Group('runtime')]
final class HarnessTest extends RuntimeTestCase
{
    private const string BROKEN_DOCKER_HOST = 'unix:///nonexistent-agentcat-docker.sock';

    /** The runtime CI job must not pass with every test skipped: with the variable set, a broken Docker fails them. */
    #[MaximumDuration(60000)]
    public function testABrokenDockerFailsTheRuntimeTestsAndWithoutTheVariableTheySkip(): void
    {
        $broken = $this->smokeRun(['AGENTCAT_RUNTIME_TESTS' => '1', 'DOCKER_HOST' => self::BROKEN_DOCKER_HOST]);
        $off = $this->smokeRun(['AGENTCAT_RUNTIME_TESTS' => '0', 'DOCKER_HOST' => self::BROKEN_DOCKER_HOST]);

        $this->assertSame(1, $broken->getExitCode(), $broken->getOutput() . $broken->getErrorOutput());
        $this->assertStringContainsString('docker info failed', $broken->getOutput());
        $this->assertStringContainsString(self::BROKEN_DOCKER_HOST, $broken->getOutput());
        $this->assertStringNotContainsString('Skipped', $broken->getOutput());
        $this->assertSame(0, $off->getExitCode(), $off->getOutput() . $off->getErrorOutput());
        $this->assertMatchesRegularExpression('/Tests: (\d+), Assertions: 0, Skipped: \1\./', $off->getOutput());
    }

    /** The output directory goes even when the container sweep and the chmod container fail; the first error stays. */
    #[MaximumDuration(60000)]
    public function testEveryCleanupStepAfterATestRunsWhenAnEarlierOneFails(): void
    {
        $out = $this->out();
        file_put_contents($out . '/written-by-the-test', 'x');

        $error = $this->withBrokenDocker(fn(): \Throwable => Thrown::by(\RuntimeException::class, fn() => $this->tearDown()));

        $this->assertStringContainsString('docker ps failed', $error->getMessage());
        $this->assertDirectoryDoesNotExist($out);
    }

    /** The collector stops even when the container sweep after the class fails. */
    #[MaximumDuration(60000)]
    public function testEveryCleanupStepAfterTheClassRunsWhenAnEarlierOneFails(): void
    {
        $port = parse_url(self::$collector->scenario(), PHP_URL_PORT);
        $this->assertIsInt($port);

        try {
            $error = $this->withBrokenDocker(static fn(): \Throwable => Thrown::by(\RuntimeException::class, static fn() => self::tearDownAfterClass()));
            $refused = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 1.0) === false;
        } finally {
            self::$collector = MockCollector::start(); // for the other tests of this class
        }

        $this->assertStringContainsString('docker ps failed', $error->getMessage());
        $this->assertTrue($refused, 'the collector still listens');
    }

    /** A killed PHPUnit run leaves its containers: the next run removes them, and leaves those of live processes. */
    #[MaximumDuration(60000)]
    public function testTheSweepRemovesTheContainersOfDeadTestProcessesOnly(): void
    {
        $pidMax = file_get_contents('/proc/sys/kernel/pid_max');
        $this->assertIsString($pidMax);
        $dead = $this->detachedSleep('agentcat-rt-' . ((int) trim($pidMax) + 1) . '-' . bin2hex(random_bytes(4))); // no process has this PID
        $live = $this->detachedSleep('agentcat-rt-1-' . bin2hex(random_bytes(4))); // PID 1 always runs

        try {
            DockerRuntime::removeStale();
            $deadLeft = $this->containerIds($dead);
            $liveLeft = $this->containerIds($live);
        } finally {
            (new Process(['docker', 'rm', '--force', $dead, $live], null, null, null, 30.0))->run();
        }

        $this->assertSame('', $deadLeft);
        $this->assertNotSame('', $liveLeft);
    }

    /** A container that a killed run leaves behind ends on its own after its lifetime. */
    #[MaximumDuration(60000)]
    public function testAStartedContainerEndsOnItsOwnAfterItsLifetime(): void
    {
        $prefix = 'agentcat-rt-' . getmypid() . '-';
        $started = microtime(true);

        DockerRuntime::start(Images::CLI, ['php', '-r', 'sleep(120);'], $this->env(), [], lifetime: 2.0);

        $this->assertNotSame('', $this->containerIds($prefix));
        $deadline = $started + 20.0;
        while ($this->containerIds($prefix) !== '' && microtime(true) < $deadline) {
            usleep(100_000);
        }
        $lived = microtime(true) - $started;
        $this->assertSame('', $this->containerIds($prefix), 'the container outlived its lifetime');
        $this->assertGreaterThan(1.5, $lived, sprintf('the container ended %.2f s after its start, before its lifetime', $lived));
    }

    /**
     * Runs the smoke test in a new PHPUnit process.
     *
     * @param array<string, string> $env
     */
    private function smokeRun(array $env): Process
    {
        $process = new Process([PHP_BINARY, self::repo() . '/vendor/bin/phpunit', '--testsuite', 'Runtime', '--group', 'runtime', '--filter', 'SmokeTest', '--colors=never'], self::repo(), $env, null, 50.0);
        $process->run();

        return $process;
    }

    /** Starts a container under `$name` that sleeps 60 s and is removed when it ends. */
    private function detachedSleep(string $name): string
    {
        $run = new Process(['docker', 'run', '--detach', '--rm', '--pull', 'never', '--name', $name, '--network', 'none', Images::CLI, 'sleep', '60'], null, null, null, 30.0);
        $run->run();
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return $name;
    }

    /**
     * The IDs of the containers whose names start with `$name`.
     *
     * @phpstan-impure containers come and go
     */
    private function containerIds(string $name): string
    {
        $list = new Process(['docker', 'ps', '--all', '--quiet', '--filter', 'name=^/' . $name], null, null, null, 30.0);
        $list->mustRun();

        return trim($list->getOutput());
    }

    /**
     * Runs `$code` while every docker command of this process reaches a socket where no daemon listens.
     *
     * @param \Closure(): \Throwable $code
     */
    private function withBrokenDocker(\Closure $code): \Throwable
    {
        $environment = Environment::clear('DOCKER_HOST');
        putenv('DOCKER_HOST=' . self::BROKEN_DOCKER_HOST);
        $_SERVER['DOCKER_HOST'] = $_ENV['DOCKER_HOST'] = self::BROKEN_DOCKER_HOST; // Symfony Process reads these

        try {
            return $code();
        } finally {
            $environment->restore();
        }
    }
}
