<?php

declare(strict_types=1);

// Usage: php tests/bin/released-versions.php
//
// Prints the releases that the `released` and `package` jobs of ci.yml and nightly.yml test (D72), as one line of
// JSON: a list of {"library", "version", "php"}. For mcp/sdk, laravel/mcp and symfony/mcp-bundle, they are the newest
// patch of each minor version from the lowest version that composer.json's require-dev allows, read from Packagist on
// each run; "php" is the lowest PHP version that CI tests and the release allows. Exits 1 when composer.json has no
// floor it can read or a library has no release at or above its floor, or 2 when Packagist cannot be read.

use AgentCat\Tests\Support\HttpGet;
use AgentCat\Tests\Support\ReleaseDiscovery;

require __DIR__ . '/../../vendor/autoload.php';

try {
    $floors = ReleaseDiscovery::floors((string) file_get_contents(__DIR__ . '/../../composer.json'));
} catch (Throwable $e) {
    fwrite(STDERR, 'released-versions: composer.json: ' . $e->getMessage() . "\n");
    exit(1);
}

// One failed GET is tried twice more, after 2 s and then 5 s: Packagist often answers again after a short pause.
$fetch = static function (string $url): string {
    $pauses = [2, 5];
    while (true) {
        try {
            return HttpGet::body($url, 'released-versions');
        } catch (RuntimeException $e) {
            $pause = array_shift($pauses);
            if ($pause === null) {
                throw $e;
            }
            fwrite(STDERR, sprintf("released-versions: %s; trying again in %d s\n", $e->getMessage(), $pause));
            sleep($pause);
        }
    }
};

try {
    $rows = ReleaseDiscovery::matrix($floors, $fetch);
} catch (RangeException $e) {
    fwrite(STDERR, 'released-versions: ' . $e->getMessage() . "\n");
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'released-versions: ' . $e->getMessage() . "\n");
    exit(2);
}

echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
