<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\FileTree;
use Illuminate\Foundation\Application;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * A Laravel application with laravel/mcp, for the adapter and bridge tests. `app.debug` is false, like production.
 * Every file Laravel writes (the package and service manifests, the caches, storage, the log, the environment file
 * and published configuration) goes to a directory of this test under the test HOME, removed after the test: none
 * goes into vendor/ (Ruling R8).
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

    private ?Environment $environment = null;

    #[\Override]
    protected function setUp(): void
    {
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
        Log::reset();
        Diagnostics::reset();
        EventQueue::setGlobal(null);
        AgentCat::setClient(null);
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();

        parent::tearDown();
        $this->environment?->restore();
        FileTree::remove($this->laravelDirectory);
    }
}
