<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Fixtures\FrankenPhp;

use AgentCat\Internal\Delivery\FinishRequest;
use AgentCat\Internal\Delivery\RequestEndWait;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// FrankenPHP classic mode: what the SDK detects in this request (the finish function and the request-end wait, read
// through its internal classes, hence AgentCat's namespace), then the buffered echo server.
header('X-AgentCat-Finish: ' . (FinishRequest::functionFor(PHP_SAPI, $_SERVER, static fn(string $name): bool => function_exists($name)) ?? 'null'));
header('X-AgentCat-Wait: ' . RequestEndWait::seconds(console: false));

require dirname(__DIR__, 3) . '/E2E/Fixtures/buffered-server.php';
