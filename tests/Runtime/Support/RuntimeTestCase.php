<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The base of the Docker runtime tests: they run only with `AGENTCAT_RUNTIME_TESTS=1`
 * (`AGENTCAT_RUNTIME_TESTS=1 vendor/bin/phpunit --testsuite Runtime --group runtime`) and skip otherwise. With the
 * variable, a `docker info` that fails or times out fails every test with its output, so the runtime CI job cannot
 * pass without running them.
 *
 * Each class has one mock collector on 127.0.0.1 (`self::$collector`). After each test and each class, every container
 * of this process is removed, also when the test failed. A test's output directory (`out()`, mounted at
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

    /** The output directories under `out()` that the fixtures write to: mcp/sdk sessions and the Laravel application. */
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

    protected static MockCollector $collector;

    /** Whether this process removed the containers of dead runs yet. */
    private static bool $swept = false;

    private ?string $out = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::assertTheRuntimeGroup();
        if (DockerRuntime::available()) {
            if (!self::$swept) {
                self::$swept = true;
                DockerRuntime::removeStale(); // a killed run's containers
            }
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
        DockerRuntime::forgetImages();
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

    /**
     * A failing runtime test names the images of the containers it started (`AGENTCAT_RUNTIME_IMAGES=latest` runs
     * floating tags, so a nightly failure must say which image broke). The original throwable stays as `previous`.
     */
    #[\Override]
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        try {
            return parent::invokeTestMethod($methodName, $testArguments);
        } catch (\Throwable $t) {
            $images = DockerRuntime::imagesUsed();
            $isExpectation = $t instanceof ExpectationFailedException;
            if ($images === [] || (!$isExpectation && str_starts_with($t::class, 'PHPUnit\\'))) { // a skip, an incomplete test or fail()
                throw $t;
            }
            $message = $t->getMessage() . "\nRuntime images: " . implode(', ', $images);
            if ($isExpectation) {
                throw new ExpectationFailedException($message, $t->getComparisonFailure(), $t);
            }

            throw new \RuntimeException(sprintf('%s: %s', $t::class, $message), 0, $t);
        }
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
        $out = $home . '/' . DockerRuntime::OUTPUT_DIRECTORY_PREFIX . bin2hex(random_bytes(6));
        foreach (['', ...self::OUT_DIRECTORIES] as $directory) {
            $path = $directory === '' ? $out : $out . '/' . $directory;
            $this->assertTrue(mkdir($path, 0o777) && chmod($path, 0o777), 'Cannot create ' . $path); // chmod: the umask
        }

        return $this->out = $out;
    }

    /** What a container wrote to `$path` under `/out` (`out()`), for example AgentCat's log in `HOME=/out`. */
    final protected function outFile(string $path): string
    {
        $file = $this->out() . '/' . $path;
        $this->assertFileExists($file);
        $contents = file_get_contents($file);
        $this->assertIsString($contents, $file);

        return $contents;
    }

    /** A free local port: the port of a URL where nothing listens. */
    final protected function freePort(): int
    {
        $port = parse_url(MockCollector::closedPortUrl(), PHP_URL_PORT);
        $this->assertIsInt($port);

        return $port;
    }

    /**
     * The environment of every container: AgentCat on, with `HOME=/out`; the API at `$collector`, or at a
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
     * Diagnostics on for every request of the container, sent to `$scenario`: add it to `env()`.
     *
     * @return array<string, string>
     */
    final protected function diagnosticsTo(string $scenario): array
    {
        return ['AGENTCAT_DISABLE_DIAGNOSTICS' => 'false', 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => $scenario];
    }

    /**
     * A 2026-07-28 `tools/call` of `echo` with `$msg` (id `$id`): no handshake, the client in `_meta`.
     *
     * @return array<string, mixed>
     */
    final protected static function echoCall(int $id, string $msg): array
    {
        $params = HttpHarness::modernParams(['name' => 'echo', 'arguments' => (object) ['msg' => $msg, 'session_id' => 'start']], ['name' => 'runtime-client', 'version' => '1.0.0']);

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params];
    }

    /**
     * The headers of `echoCall()`, with `$scenario` as the AgentCat API of a fixture that builds its server per request
     * (`X-E2E-Collector`).
     *
     * @return array<string, string>
     */
    final protected static function echoHeaders(string $scenario): array
    {
        return [...HttpHarness::modernHeaders('tools/call', 'echo'), 'X-E2E-Collector' => $scenario];
    }

    /**
     * The answer to `echoCall($id, $msg)`: status 200, and `echo: <msg>` after the issued session block.
     *
     * @param array{status: int, headers: array<string, string>, body: string, receivedAt: float} $answer
     */
    final protected function assertEchoed(string $msg, array $answer, int $id): void
    {
        $this->assertSame(200, $answer['status'], $answer['body']);
        $result = HttpHarness::result(HttpHarness::decode($answer['body'], $answer['headers']['content-type'] ?? '', $id));
        $this->assertSame('echo: ' . $msg, JsonAssert::at($result, 'content', 1, 'text'));
    }

    /**
     * Sends the untimed warm-up call (`echoCall(1, 'warm')` with `echoHeaders($scenario)` and `$headers`) and waits until
     * `$scenario` has its delivery, so the timed request meets a warm runtime and no drain of the warm-up.
     *
     * @param array<string, string> $headers
     */
    final protected function warmUp(McpHttpClient $client, string $scenario, float $timeout, array $headers = []): void
    {
        $this->assertEchoed('warm', $client->post(self::echoCall(1, 'warm'), [...self::echoHeaders($scenario), ...$headers]), 1);
        $this->waitForRequests($scenario, 1, $timeout);
    }

    /**
     * `$scenario` received `$count` deliveries, and answered the last one more than 1 s after the client had its answer.
     *
     * @param array{status: int, headers: array<string, string>, body: string, receivedAt: float} $answer
     */
    final protected function assertDeliveredAfter(array $answer, string $scenario, float $timeout, int $count = 1): void
    {
        $deliveries = $this->waitForRequests($scenario, $count, $timeout);
        $this->assertCount($count, $deliveries);
        $last = $deliveries[$count - 1] ?? null;
        $this->assertInstanceOf(\stdClass::class, $last);
        $this->assertGreaterThan($answer['receivedAt'] + 1.0, $this->time($last, 'end'), 'the collector answered the delivery after the client had its answer');
    }

    /**
     * Waits until `$diagnostics` has at least `$count` posts with a `Successfully sent event` line: that many drains
     * logged their delivery. The collector records an event also when the drain dies, so only this proves the drain.
     */
    final protected function waitForSentEventPosts(string $diagnostics, int $count, float $timeout): void
    {
        $this->waitUntil($diagnostics, static fn(array $posts): bool => count(MockCollector::withASentEvent($posts)) >= $count, $timeout, sprintf('%d diagnostics posts with a sent event, one per drain that logged its delivery', $count));
    }

    /** PHP logged no error, warning, notice or deprecation to the container's output (`log_errors` on, no `error_log`). */
    final protected function assertNoPhpErrors(DockerRuntime $runtime): void
    {
        $this->assertDoesNotMatchRegularExpression('/PHP (Warning|Notice|Deprecated)|Fatal error/', $runtime->logs());
    }

    /** When the collector received (`start`) or answered (`end`, after its delay) a request. */
    final protected function time(\stdClass $request, string $field): float
    {
        $time = $request->{$field} ?? null;
        $this->assertIsFloat($time);

        return $time;
    }

    /**
     * Waits until the collector's scenario has at least `$count` requests, and fails the test after `$timeout` seconds.
     *
     * @return list<\stdClass> every request of the scenario so far, in arrival order
     */
    final protected function waitForRequests(string $scenario, int $count, float $timeout): array
    {
        return $this->waitUntil($scenario, static fn(array $requests): bool => count($requests) >= $count, $timeout, sprintf('%d requests reached the collector', $count));
    }

    /**
     * Waits until `$done` accepts the requests of the collector's scenario, and fails the test after `$timeout` seconds
     * with `$what` and the number of requests.
     *
     * @param \Closure(list<\stdClass>): bool $done
     * @param string                          $what what `$done` waits for, for the failure message
     *
     * @return list<\stdClass> every request of the scenario so far, in arrival order
     */
    final protected function waitUntil(string $scenario, \Closure $done, float $timeout, string $what): array
    {
        $requests = self::$collector->waitFor($scenario, $done, $timeout);
        $this->assertTrue($done($requests), sprintf('Not within %.1f s: %s (%d requests)', $timeout, $what, count($requests)));

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
