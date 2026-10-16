<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // ext-posix is optional: FileLogger guards its use with function_exists().
    ->ignoreErrorsOnExtensions(['ext-posix'], [ErrorType::SHADOW_DEPENDENCY])
    // Test fixture functions, loaded with require_once by ExceptionCaptureTest (not autoloadable).
    ->ignoreUnknownFunctionsRegex('~^AgentCat\\\\Tests\\\\Fixtures\\\\Errors\\\\~');
