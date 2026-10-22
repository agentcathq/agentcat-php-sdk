<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // ext-pcntl and ext-posix are optional: FileLogger and SignalDrain guard their use with function_exists().
    ->ignoreErrorsOnExtensions(['ext-pcntl', 'ext-posix'], [ErrorType::SHADOW_DEPENDENCY])
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
    ->ignoreErrorsOnPackageAndPath('php-http/discovery', __DIR__ . '/tests/E2E/Fixtures', [ErrorType::SHADOW_DEPENDENCY]);
