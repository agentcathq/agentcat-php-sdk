<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Runtime dependencies are declared before the code that uses them lands (later plans).
// Remove these ignores once the code uses them.
return (new Configuration())
    ->ignoreErrorsOnPackages(['psr/log'], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnExtensions(['ext-curl', 'ext-mbstring'], [ErrorType::UNUSED_DEPENDENCY]);
