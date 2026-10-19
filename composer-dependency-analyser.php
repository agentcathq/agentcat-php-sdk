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
    // Orchestra\Testbench\TestCase lives in orchestra/testbench-core, which orchestra/testbench requires; the Laravel tests extend it.
    ->ignoreErrorsOnPackageAndPath('orchestra/testbench-core', __DIR__ . '/tests', [ErrorType::SHADOW_DEPENDENCY])
    // Symfony\Component\Console arrives with laravel/framework; the artisan fixture hands its input and output to Laravel's console kernel.
    ->ignoreErrorsOnPackageAndPath('symfony/console', __DIR__ . '/tests/E2E/Laravel', [ErrorType::SHADOW_DEPENDENCY])
    // Symfony\Component\Routing arrives with symfony/framework-bundle and symfony/mcp-bundle; the test kernel imports the bundle's MCP routes with it.
    ->ignoreErrorsOnPackageAndPath('symfony/routing', __DIR__ . '/tests/Support/Symfony/TestKernel.php', [ErrorType::SHADOW_DEPENDENCY])
    // The test kernel registers AgentCat's Symfony bundle, whose class src/Symfony/AgentCatBundle.php is not written yet. Remove this line with it.
    ->ignoreUnknownClasses(['AgentCat\Symfony\AgentCatBundle']);
