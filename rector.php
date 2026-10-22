<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

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
        // Names test-runner classes as strings: PHPUnit and Pest are not dependencies of the SDK (D31).
        StringClassNameToClassConstantRector::class => [__DIR__ . '/src/Internal/Support/TestMode.php'],
    ])
    ->withCache(__DIR__ . '/.cache/rector');
