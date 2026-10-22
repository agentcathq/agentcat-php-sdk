<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__DIR__ . '/rector.php', __DIR__ . '/composer-dependency-analyser.php', __FILE__]);

return (new Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.cache/php-cs-fixer.cache')
    ->setRules([
        '@PER-CS3x0' => true,
        '@PER-CS3x0:risky' => true,
        '@PHP8x3Migration' => true,
        '@PHP8x3Migration:risky' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        'void_return' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'global_namespace_import' => ['import_classes' => false],
    ])
    ->setFinder($finder);
