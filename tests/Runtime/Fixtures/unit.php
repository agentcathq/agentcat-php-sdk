<?php

declare(strict_types=1);

// UnitTest: NGINX Unit runs every request through this script. It names the SAPI that Unit reports, then runs the
// FrankenPHP classic fixture (what the SDK detects, then the buffered echo server), with the answer's Content-Length,
// or without it when the request sends `X-E2E-Unsized: 1`. The paths of the two bridges' servers run their front
// controllers instead (the Laravel application's `/mcp/e2e`, the Symfony application's `/mcp/conformance`).
header('X-E2E-Sapi: ' . PHP_SAPI);

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? parse_url($uri, PHP_URL_PATH) : null;
if ($path === '/mcp/e2e') {
    require __DIR__ . '/laravel-front.php';

    return;
}
if ($path === '/mcp/conformance') {
    require __DIR__ . '/symfony-front.php';

    return;
}

require __DIR__ . '/frankenphp/' . (($_SERVER['HTTP_X_E2E_UNSIZED'] ?? null) === '1' ? 'unsized.php' : 'classic.php');
