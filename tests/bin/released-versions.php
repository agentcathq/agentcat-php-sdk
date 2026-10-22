<?php

declare(strict_types=1);

// Usage: php tests/bin/released-versions.php
//
// Prints the releases that the `released` jobs of ci.yml and nightly.yml test (D72), as one line of JSON: a list of
// {"library": ..., "version": ...}. For mcp/sdk, laravel/mcp and symfony/mcp-bundle, they are the newest patch of each
// minor version from the lowest version that composer.json's require-dev allows, read from Packagist on each run.
// Exits 1 when a library has no release at or above that floor, or 2 when Packagist cannot be read.

use AgentCat\Tests\Support\HttpGet;
use AgentCat\Tests\Support\ReleaseDiscovery;

require __DIR__ . '/../../vendor/autoload.php';

$floors = ReleaseDiscovery::floors((string) file_get_contents(__DIR__ . '/../../composer.json'));

$rows = [];
try {
    foreach ($floors as $library => $floor) {
        $body = HttpGet::body(ReleaseDiscovery::metadataUrl($library), 'released-versions');
        $versions = ReleaseDiscovery::newestPatches(ReleaseDiscovery::stableVersions($body, $library), $floor);
        if ($versions === []) {
            fwrite(STDERR, sprintf("released-versions: Packagist has no release of %s at or above %s\n", $library, $floor));
            exit(1);
        }
        foreach ($versions as $version) {
            $rows[] = ['library' => $library, 'version' => $version];
        }
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, 'released-versions: ' . $e->getMessage() . "\n");
    exit(2);
}

echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
