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

[$code, $text] = Release::main(array_slice($argv, 1), dirname(__DIR__, 2));
fwrite($code === 0 ? STDOUT : STDERR, $text . "\n");
exit($code);
