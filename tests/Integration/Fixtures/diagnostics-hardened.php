<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A hardened host (run with -d disable_functions=php_uname,getmypid,gethostname): diagnostics must still start, and
// post one line on shutdown. Writes what it observed to the file named by the first argument.
$http = new RecordingHttpChannel();
$queue = new EventQueue(transport: new RecordingTransport(), channel: $http);
Diagnostics::init('', false, $queue);
Log::write('Warning: hardened');
$queue->shutdown();
file_put_contents($argv[1] ?? '', json_encode([
    'enabled' => Diagnostics::enabled(),
    'urls' => $http->urls(),
    'body' => isset($http->requests[0]) ? $http->requests[0]->body : null,
], JSON_THROW_ON_ERROR));
