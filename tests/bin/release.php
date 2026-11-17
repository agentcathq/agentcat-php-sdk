<?php

declare(strict_types=1);

// Usage: php tests/bin/release.php prepare <patch | minor | major | X.Y.Z> <YYYY-MM-DD>
//        php tests/bin/release.php status
//        php tests/bin/release.php notes <X.Y.Z>
//
// The steps of .github/workflows/release.yml, on CHANGELOG.md and the v* tags (tests/Support/Release.php says what each
// step does). It runs with PHP alone, before composer install: it loads its one class by path, not with Composer's
// autoloader. Exits 1 when a step fails, or 2 with the usage for unknown arguments.

use AgentCat\Tests\Support\Release;

require_once __DIR__ . '/../Support/Release.php';

// $_SERVER, not $argv: PHPStan on PHP 8.5 cannot prove that the global $argv exists.
$serverArgv = $_SERVER['argv'] ?? [];
$arguments = array_values(array_filter(array_slice(is_array($serverArgv) ? $serverArgv : [], 1), is_string(...)));

[$code, $text] = Release::main($arguments, dirname(__DIR__, 2));
fwrite($code === 0 ? STDOUT : STDERR, $text . "\n");
exit($code);
