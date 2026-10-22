<?php

declare(strict_types=1);

// SmokeTest: the repository is the working directory, mounted read-only; its autoloader and the SDK load in the image.
// A script, not `php -r`: the Swoole image's entrypoint splits its arguments at spaces (`exec $@`).
require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (!class_exists(AgentCat\AgentCat::class)) {
    fwrite(STDERR, 'The SDK does not load from ' . getcwd());

    exit(1);
}

echo PHP_VERSION, ' ', extension_loaded('curl') ? 'curl' : '-', ' ', extension_loaded('mbstring') ? 'mbstring' : '-';
