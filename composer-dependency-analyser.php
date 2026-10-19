<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // ext-pcntl, ext-posix and ext-session are optional: FileLogger, SignalDrain and RequestEnd guard their use with function_exists().
    ->ignoreErrorsOnExtensions(['ext-pcntl', 'ext-posix', 'ext-session'], [ErrorType::SHADOW_DEPENDENCY])
    // Test fixture functions, loaded with require_once by ExceptionCaptureTest (not autoloadable).
    ->ignoreUnknownFunctionsRegex('~^AgentCat\\\\Tests\\\\Fixtures\\\\Errors\\\\~')
    // mcp/sdk is optional (01 AC-90): a dev dependency and a suggestion, used only by the adapter.
    ->ignoreErrorsOnPackageAndPath('mcp/sdk', __DIR__ . '/src/McpSdk', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // AgentCatFake asserts with PHPUnit only when the class is loaded (class_exists()); phpunit stays a dev dependency.
    ->ignoreErrorsOnPackageAndPath('phpunit/phpunit', __DIR__ . '/src/Testing', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // Psr\Container\ContainerInterface arrives with mcp/sdk; the adapter only passes it through.
    ->ignoreErrorsOnPackageAndPath('psr/container', __DIR__ . '/src/McpSdk', [ErrorType::SHADOW_DEPENDENCY])
    // Psr\EventDispatcher\EventDispatcherInterface arrives with mcp/sdk; the test double stands in for a customer's dispatcher.
    ->ignoreErrorsOnPackageAndPath('psr/event-dispatcher', __DIR__ . '/tests/Support/McpSdk/RecordingDispatcher.php', [ErrorType::SHADOW_DEPENDENCY])
    // Http\Discovery\Psr17Factory arrives with mcp/sdk; the HTTP fixture builds its request from the globals with it, like a customer.
    ->ignoreErrorsOnPackageAndPath('php-http/discovery', __DIR__ . '/tests/E2E/Fixtures', [ErrorType::SHADOW_DEPENDENCY])
    // laravel/mcp and Laravel are optional (01 AC-90): dev dependencies used only by the Laravel adapter and bridge.
    ->ignoreErrorsOnPackageAndPath('laravel/mcp', __DIR__ . '/src/LaravelMcp', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('laravel/mcp', __DIR__ . '/src/Laravel', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('laravel/framework', __DIR__ . '/src/LaravelMcp', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPath('laravel/framework', __DIR__ . '/src/Laravel', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // False positives: the analyser reads AgentCat's own `Env::get()` and `new Event(` as Laravel's global env() and
    // event() helpers. PHPat keeps Laravel out of these files (LayeringTest).
    ->ignoreErrorsOnPackageAndPaths('laravel/framework', [__DIR__ . '/src/Internal/Support/TestMode.php', __DIR__ . '/src/Internal/Support/BridgeConfig.php', __DIR__ . '/src/Client.php'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // Symfony is optional (01 AC-90): dev dependencies used only by the Symfony bundle.
    ->ignoreErrorsOnPackagesAndPaths(['symfony/config', 'symfony/dependency-injection', 'symfony/http-foundation', 'symfony/http-kernel'], [__DIR__ . '/src/Symfony'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // The bundle configures the Mcp\Server\Builder services of symfony/mcp-bundle, which requires mcp/sdk.
    ->ignoreErrorsOnPackageAndPath('mcp/sdk', __DIR__ . '/src/Symfony', [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // Psr\Container\ContainerInterface arrives with symfony/dependency-injection; the tracker reads the hook locator through it.
    ->ignoreErrorsOnPackageAndPath('psr/container', __DIR__ . '/src/Symfony', [ErrorType::SHADOW_DEPENDENCY])
    // Symfony\Component\EventDispatcher arrives with symfony/http-kernel; the bundle adds its kernel.response listener to the kernel's dispatcher, and the bundle tests read the listeners from it.
    ->ignoreErrorsOnPackageAndPaths('symfony/event-dispatcher', [__DIR__ . '/src/Symfony', __DIR__ . '/tests/Support/Symfony', __DIR__ . '/tests/Unit/Symfony', __DIR__ . '/tests/Integration/Symfony'], [ErrorType::SHADOW_DEPENDENCY])
    // Orchestra\Testbench\TestCase lives in orchestra/testbench-core, which orchestra/testbench requires; the Laravel tests extend it.
    ->ignoreErrorsOnPackageAndPath('orchestra/testbench-core', __DIR__ . '/tests', [ErrorType::SHADOW_DEPENDENCY])
    // Symfony\Component\Console arrives with laravel/framework; the artisan fixture hands its input and output to Laravel's console kernel.
    ->ignoreErrorsOnPackageAndPath('symfony/console', __DIR__ . '/tests/E2E/Laravel', [ErrorType::SHADOW_DEPENDENCY])
    // Symfony\Component\Console arrives with symfony/framework-bundle; a bundle test runs a console command as an application does.
    ->ignoreErrorsOnPackageAndPath('symfony/console', __DIR__ . '/tests/Integration/Symfony/BundleTest.php', [ErrorType::SHADOW_DEPENDENCY])
    // Symfony\Component\Routing arrives with symfony/framework-bundle and symfony/mcp-bundle; the test kernel imports the bundle's MCP routes with it, and a contract test reads them.
    ->ignoreErrorsOnPackageAndPaths('symfony/routing', [__DIR__ . '/tests/Support/Symfony/TestKernel.php', __DIR__ . '/tests/Contract/Symfony/BundleServicesTest.php'], [ErrorType::SHADOW_DEPENDENCY]);
