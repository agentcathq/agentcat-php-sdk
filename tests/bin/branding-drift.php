<?php

declare(strict_types=1);

// Usage: php tests/bin/branding-drift.php
//
// Compares the TypeScript SDK's main branch with the copies that the README's branding comes from (D65): the shared
// sections of its README with tests/Fixtures/branding/typescript-readme.md, and its README images with the hashes of
// tests/Fixtures/branding/images.php. Prints each changed section or image by name and exits 1 on any change, or 2
// when GitHub cannot be read. A person then updates the fixture and the README, and for an image also the copy under
// docs/ and its hash. With GITHUB_TOKEN set, the GitHub API call sends it as a bearer token.

use AgentCat\Tests\Support\Branding;

require __DIR__ . '/../../vendor/autoload.php';

const README_URL = 'https://raw.githubusercontent.com/agentcathq/agentcat-typescript-sdk/main/README.md';
const TREE_URL = 'https://api.github.com/repos/agentcathq/agentcat-typescript-sdk/git/trees/main?recursive=1';
const FIXTURES = __DIR__ . '/../Fixtures/branding';

// The body of a GET that answered 200; a RuntimeException when the request fails or answers another status.
$get = static function (string $url, string ...$headers): string {
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('GET ' . $url . ': curl_init() failed');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'agentcat-php-sdk branding-drift',
        CURLOPT_HTTPHEADER => array_values($headers),
    ]);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    if (!is_string($body) || $status !== 200) {
        throw new RuntimeException(sprintf('GET %s: %s', $url, $error !== '' ? $error : 'HTTP ' . $status));
    }

    return $body;
};

try {
    $images = require FIXTURES . '/images.php';
    if (!is_array($images)) {
        throw new UnexpectedValueException('images.php must return path => blob SHA-1');
    }
    $fixtureImages = [];
    foreach ($images as $path => $hash) {
        if (!is_string($path) || !is_string($hash)) {
            throw new UnexpectedValueException('images.php must return path => blob SHA-1');
        }
        $fixtureImages[$path] = $hash;
    }
    $copy = Branding::typescriptReadme(FIXTURES . '/typescript-readme.md');

    $apiHeaders = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '') {
        $apiHeaders[] = 'Authorization: Bearer ' . $token;
    }
    $upstream = $get(README_URL);
    $upstreamImages = Branding::treeHashes($get(TREE_URL, ...$apiHeaders));
} catch (Throwable $e) {
    fwrite(STDERR, 'branding-drift: ' . $e->getMessage() . "\n");
    exit(2);
}

$changes = Branding::drift($copy, $upstream, $fixtureImages, $upstreamImages);
if ($changes === []) {
    printf("The shared README sections and the %d images match agentcat-typescript-sdk main.\n", count($fixtureImages));
    exit(0);
}

echo "agentcat-typescript-sdk main changed what the README shares with it:\n\n";
foreach ($changes as $change) {
    echo '- ', $change, "\n";
}
echo "\nUpdate tests/Fixtures/branding (and README.md, and for an image its copy under docs/) to match.\n";
exit(1);
