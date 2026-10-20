<?php

declare(strict_types=1);

// UnitTest: NGINX Unit runs every request through this script. It names the SAPI that Unit reports, then runs the
// FrankenPHP classic fixture (what the SDK detects, then the buffered echo server), with the answer's Content-Length,
// or without it when the request sends `X-E2E-Unsized: 1`.
header('X-E2E-Sapi: ' . PHP_SAPI);

require __DIR__ . '/frankenphp/' . (($_SERVER['HTTP_X_E2E_UNSIZED'] ?? null) === '1' ? 'unsized.php' : 'classic.php');
