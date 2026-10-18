<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// The `php -S` router: Laravel's own front-controller steps (public/index.php).
$app = require __DIR__ . '/bootstrap.php';
if (!$app instanceof Application) {
    throw new LogicException('bootstrap.php returns the application');
}
$kernel = $app->make(Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
