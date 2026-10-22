<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

// `php artisan mcp:start <handle>`: the handle comes from AGENTCAT_E2E_HANDLE, `e2e` by default.
$app = require __DIR__ . '/bootstrap.php';
if (!$app instanceof Application) {
    throw new LogicException('bootstrap.php returns the application');
}
$handle = getenv('AGENTCAT_E2E_HANDLE');
$kernel = $app->make(Kernel::class);
$input = new ArgvInput(['artisan', 'mcp:start', is_string($handle) && $handle !== '' ? $handle : 'e2e']);
$status = $kernel->handle($input, new ConsoleOutput());
$kernel->terminate($input, $status);
exit($status);
