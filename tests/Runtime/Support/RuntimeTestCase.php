<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The base of the Docker runtime tests (header conflict 10): they run only with `AGENTCAT_RUNTIME_TESTS=1`
 * (`AGENTCAT_RUNTIME_TESTS=1 vendor/bin/phpunit --testsuite Runtime --group runtime`) and skip otherwise. With the
 * variable, a `docker info` that fails or times out fails every test with its output, so the runtime CI job cannot
 * pass without running them.
 *
 * Each class has one mock collector on 127.0.0.1 (`self::$collector`). After each test and each class, every container
 * of this process is removed, also when the test failed (ruling P14). A test's output directory (`out()`, mounted at
 * `/out`) lives under the test HOME and is removed after the test.
 *
 * PHPUnit does not inherit groups: every subclass carries `#[Group('runtime')]` itself, or the class fails in
 * `setUpBeforeClass()`.
 */
#[Group('runtime')]
abstract class RuntimeTestCase extends TestCase
{
    protected const string PROJECT_ID = 'proj_eeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const string GROUP = 'runtime';

    /** The output directories under `out()` that the fixtures write to: mcp/sdk sessions and the Laravel application (ruling P15). */
    private const array OUT_DIRECTORIES = [
        'sessions',
        'app',
        'app/bootstrap',
        'app/bootstrap/cache',
        'app/storage',
        'app/storage/framework',
        'app/storage/framework/views',
        'app/storage/logs',
    ];

    private const int POLL_MICROSECONDS = 20_000;

    protected static MockCollector $collector;

    private ?string $out = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::assertTheRuntimeGroup();
        if (DockerRuntime::available()) {
            self::$collector = MockCollector::start();
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::runEach([
            static function (): void {
                if (DockerRuntime::available()) {
                    DockerRuntime::removeAll(); // also a container whose docker client a test's timeout killed
                }
            },
            static function (): void {
                if (isset(self::$collector)) {
                    self::$collector->stop();
                }
            },
        ]);
    }

    #[\Override]
    protected function setUp(): void
    {
        if (!DockerRuntime::requested()) {
            self::markTestSkipped('The runtime tests run with AGENTCAT_RUNTIME_TESTS=1');
        }
        $problem = DockerRuntime::problem();
        if ($problem !== null) {
            self::fail('AGENTCAT_RUNTIME_TESTS=1, but ' . $problem);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!DockerRuntime::available()) {
            return;
        }
        $out = $this->out;
        $this->out = null;
        self::runEach([
            static function (): void {
                DockerRuntime::removeAll();
            },
            static function () use ($out): void {
                if ($out !== null) {
                    DockerRuntime::makeRemovable($out); // files of root and www-data that this user cannot remove
                }
            },
            static function () use ($out): void {
                if ($out !== null) {
                    FileTree::remove($out);
                }
            },
        ]);
    }

    /** The repository's absolute path, which every container mounts read-only at the same path. */
    final protected static function repo(): string
    {
        return DockerRuntime::repo();
    }

    /**
     * The test's output directory under the test HOME (mode 0777, with `sessions/` and the Laravel application's
     * `app/` directories): mount it at `/out`, the only place a container writes. Removed after the test.
     */
    final protected function out(): string
    {
        if ($this->out !== null) {
            return $this->out;
        }
        $home = getenv('HOME');
        $this->assertIsString($home);
        $out = $home . '/runtime-' . bin2hex(random_bytes(6));
        foreach (['', ...self::OUT_DIRECTORIES] as $directory) {
            $path = $directory === '' ? $out : $out . '/' . $directory;
            $this->assertTrue(mkdir($path, 0o777) && chmod($path, 0o777), 'Cannot create ' . $path); // chmod: the umask
        }

        return $this->out = $out;
    }

    /** A free local port: the port of a URL where nothing listens. */
    final protected function freePort(): int
    {
        $port = parse_url(MockCollector::closedPortUrl(), PHP_URL_PORT);
        $this->assertIsInt($port);

        return $port;
    }

    /**
     * The environment of every container (ruling P15): AgentCat on, with `HOME=/out`; the API at `$collector`, or at a
     * closed local port so that a fixture which forgets its collector fails closed; diagnostics off and at a closed
     * port (a fixture turns them on for one request with `X-E2E-Diagnostics`); the fixtures' sessions, markers and
     * Laravel application under `/out`.
     *
     * @return array<string, string>
     */
    final protected function env(?string $collector = null): array
    {
        $collector ??= MockCollector::closedPortUrl();

        return [
            'AGENTCAT_ENABLED' => 'true',
            'AGENTCAT_PROJECT_ID' => self::PROJECT_ID,
            'AGENTCAT_API_URL' => $collector,
            'AGENTCAT_E2E_COLLECTOR' => $collector,
            'HOME' => '/out',
            'AGENTCAT_DISABLE_DIAGNOSTICS' => 'true',
            'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(),
            'AGENTCAT_E2E_SESSIONS' => '/out/sessions',
            'AGENTCAT_E2E_MARKERS' => '/out',
            'APP_BASE_PATH' => '/out/app',
        ];
    }

    /**
     * Waits until the collector's scenario has at least `$count` requests, and fails the test after `$timeout` seconds.
     *
     * @return list<\stdClass> every request of the scenario so far, in arrival order
     */
    final protected function waitForRequests(string $scenario, int $count, float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        $requests = self::$collector->requests($scenario);
        while (count($requests) < $count && microtime(true) < $deadline) {
            usleep(self::POLL_MICROSECONDS);
            $requests = self::$collector->requests($scenario);
        }
        $this->assertGreaterThanOrEqual($count, count($requests), sprintf('%d of %d requests reached the collector within %.1f s', count($requests), $count, $timeout));

        return $requests;
    }

    /**
     * Runs every cleanup step, also after one throws, and then throws the first error.
     *
     * @param list<\Closure(): void> $steps
     */
    private static function runEach(array $steps): void
    {
        $first = null;
        foreach ($steps as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $first ??= $e;
            }
        }
        if ($first instanceof \Throwable) {
            throw $first;
        }
    }

    /** Without the group, `--group runtime` (the runtime CI job) never runs the class. */
    private static function assertTheRuntimeGroup(): void
    {
        foreach ((new \ReflectionClass(static::class))->getAttributes(Group::class) as $attribute) {
            if ($attribute->newInstance()->name() === self::GROUP) {
                return;
            }
        }
        self::fail(sprintf("%s must carry #[Group('%s')]: PHPUnit does not inherit it from %s", static::class, self::GROUP, self::class));
    }
}
