<?php

declare(strict_types=1);

// FpmTest: PHP-FPM runs the Laravel application of the E2E tests (tests/E2E/Laravel/bootstrap.php, with AgentCat as the
// auto-discovered service provider) through Laravel's own front-controller steps, as the `php -S` router does.
require dirname(__DIR__, 2) . '/E2E/Laravel/http.php';
