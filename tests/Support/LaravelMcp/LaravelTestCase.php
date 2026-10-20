<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\BridgeConfig;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\GlobalHandlers;
use AgentCat\Tests\Support\SrcErrorGuard;
use AgentCat\Tests\Support\ToolCalls;
use AgentCat\Tests\Support\WarmUp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\After;

/**
 * A Laravel application with laravel/mcp, for the adapter and bridge tests. `app.debug` is false, like production.
 * Every file Laravel writes (the package and service manifests, the caches, storage, the log, the environment file
 * and published configuration) goes to a directory of this test under the test HOME, removed after the test: none
 * goes into vendor/ (Ruling R8). A notice, warning or deprecation from a file of `src/` that Laravel's error handler
 * takes (it logs a deprecation and turns any other issue into an exception, which a request answers with an error)
 * fails the test (`SrcErrorGuard`).
 */
abstract class LaravelTestCase extends TestCase
{
    /** The variables that move Laravel's cache files and storage out of the Testbench skeleton in vendor/. */
    private const array PATHS = [
        'APP_PACKAGES_CACHE' => 'cache/packages.php',
        'APP_SERVICES_CACHE' => 'cache/services.php',
        'APP_CONFIG_CACHE' => 'cache/config.php',
        'APP_ROUTES_CACHE' => 'cache/routes.php',
        'APP_EVENTS_CACHE' => 'cache/events.php',
        'LARAVEL_STORAGE_PATH' => 'storage',
    ];

    /** This test's directory under the test HOME. */
    protected string $laravelDirectory = '';

    /** Whether this process built its warm-up application (`setUpBeforeClass()`). */
    private static bool $warm = false;

    private ?Environment $environment = null;

    /** The error and exception handler stacks before the application, put back after the test. */
    private ?GlobalHandlers $handlers = null;

    /**
     * The first Laravel application of a PHP process is slow: PHP compiles the framework's files then (about 0.07 s on
     * PHP 8.5, up to 0.3 s on a loaded host, against 0.01 s for each later one). So, once per process and before the
     * first test class runs, a test case of its own builds one application, sends it one request and tears it down,
     * outside the time the slow-test detector measures for a test. `WarmUp::run()` makes a
     * notice, warning or deprecation from a file of `src/` fail the class, as it would fail the test that included it.
     */
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        if (!self::$warm) {
            self::$warm = true;
            self::warmUp();
        }
        parent::setUpBeforeClass();
    }

    /** Runs one test case's whole lifecycle, as PHPUnit does, with one request: a 404, through Laravel's HTTP kernel. */
    private static function warmUp(): void
    {
        $warmUp = new class ('testWarmUp') extends LaravelTestCase {
            public function testWarmUp(): void
            {
                $this->get('/agentcat-warm-up'); // a 404; no assertion, which PHPUnit would count for the next test
            }
        };
        WarmUp::run(static function () use ($warmUp): void {
            $warmUp::setUpBeforeClass();
            $warmUp->setUp();
            try {
                $warmUp->testWarmUp();
            } finally {
                $warmUp->tearDown();
                $warmUp->assertNoSrcIssueWasHidden();
                $warmUp::tearDownAfterClass();
            }
        });
    }

    #[\Override]
    protected function setUp(): void
    {
        SrcErrorGuard::forget();
        $this->handlers = GlobalHandlers::save();
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new \LogicException('tests/bootstrap.php sets HOME to the test HOME');
        }
        $this->laravelDirectory = $home . '/laravel-' . bin2hex(random_bytes(6));
        foreach (['cache', 'config', 'storage/framework/views'] as $directory) {
            if (!mkdir($this->laravelDirectory . '/' . $directory, 0o700, true)) {
                throw new \RuntimeException('Cannot create ' . $this->laravelDirectory . '/' . $directory);
            }
        }
        $this->environment = Environment::clear(...array_keys(self::PATHS));
        foreach (self::PATHS as $name => $path) {
            $value = $this->laravelDirectory . '/' . $path;
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        parent::setUp();
    }

    /**
     * Each new application (`setUp()`, or a test's own refresh) installs Laravel's error handler, then registers and
     * boots the providers, AgentCat's among them. The guard goes in front of that handler before the providers register
     * (Testbench resolves `RegisterProviders` right after `HandleExceptions`), and again after the application if a
     * handler came in front of it since.
     *
     * @param Application $app
     */
    #[\Override]
    protected function resolveApplicationResolvingCallback(mixed $app): void
    {
        parent::resolveApplicationResolvingCallback($app);
        $app->afterResolving(RegisterProviders::class, static function (): void {
            SrcErrorGuard::install();
        });
    }

    #[\Override]
    protected function refreshApplication(): void
    {
        parent::refreshApplication();
        SrcErrorGuard::install();
    }

    /** @return list<class-string> */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [McpServiceProvider::class];
    }

    /**
     * Runs after the providers registered and before they boot, so `publishes()` targets the configuration path. A
     * test that overrides it calls the parent first.
     *
     * @param Application $app
     */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        $app->useConfigPath($this->laravelDirectory . '/config');
        $app->useEnvironmentPath($this->laravelDirectory);
        config([
            'app.debug' => false,
            'logging.default' => 'single',
            'logging.channels.single' => ['driver' => 'single', 'path' => $this->laravelDirectory . '/laravel.log'],
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ToolCalls::reset();
        CustomCallTool::reset();
        TestMode::reset(); // also the configured switch that AgentCat's service provider sets
        BridgeConfig::reset(); // and the configured values
        Log::reset();
        Diagnostics::reset();
        EventQueue::setGlobal(null);
        AgentCat::setClient(null);
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();

        parent::tearDown(); // Laravel's flushState() empties both stacks and puts PHPUnit's handler back, also before a throw
        try {
            $this->environment?->restore();
            FileTree::remove($this->laravelDirectory);
        } finally {
            $this->handlers?->restore();
        }
    }

    /**
     * Fails with each `src/` issue that a guard recorded. It runs after every `tearDown()` of the test, the subclasses'
     * too, so its failure skips none of their cleanup; `setUp()` forgets what a test that threw earlier left.
     */
    #[After(-100)]
    public function assertNoSrcIssueWasHidden(): void
    {
        SrcErrorGuard::assertNone($this);
    }
}
