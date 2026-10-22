<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\CodeQuality\Rector\ClassMethod\NoSetupWithParentCallOverrideRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        instanceOf: true,
        phpunitCodeQuality: true,
    )
    ->withSkip([
        // Keeps #[\Override] on setUp() and tearDown() that call the parent: PHPStan's checkMissingOverrideMethodAttribute requires it.
        NoSetupWithParentCallOverrideRector::class,
        // Names test-runner classes as strings: PHPUnit and Pest are not dependencies of the SDK (D31). AfterRequest
        // names the Octane event class, which is not a dependency either.
        StringClassNameToClassConstantRector::class => [__DIR__ . '/src/Internal/Support/TestMode.php', __DIR__ . '/src/Laravel/Internal/AfterRequest.php'],
    ])
    ->withCache(__DIR__ . '/.cache/rector');
