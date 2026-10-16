<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

// Runtime dependencies are declared before the code that uses them lands (later plans).
// Remove this ignore once the code uses ext-curl.
return (new Configuration())
    ->ignoreErrorsOnExtensions(['ext-curl'], [ErrorType::UNUSED_DEPENDENCY])
    // ext-posix is optional: FileLogger guards its use with function_exists().
    ->ignoreErrorsOnExtensions(['ext-posix'], [ErrorType::SHADOW_DEPENDENCY]);
