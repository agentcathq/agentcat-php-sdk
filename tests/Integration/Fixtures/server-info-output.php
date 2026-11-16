<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Heartbeat;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\Options;
use AgentCat\Tests\Support\FakeServerInfo;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use AgentCat\Tests\Support\RecordingTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A worker that writes its responses with echo. Between response 0 and response 1, one server info heartbeat runs in
// the request-end window, and its list handler prints text and leaves a bad output buffer open: one that PHP cannot
// remove (`stuck`, or `flushable`, which the worker flushes after each response), or one whose handler throws when the
// buffer is cleaned (`throwing`). Nothing is sent: the queue
// records. The last lines say which PHP errors the error handler of the "application" saw, and how many heartbeats
// were queued.
$mode = $argv[1] ?? '';
$logger = new MemoryLogger();
Log::setLogger($logger);
$seen = [];
set_error_handler(static function (int $severity, string $message) use (&$seen): bool {
    $seen[] = $message;

    return true;
});
$channel = new RecordingHttpChannel();
$queue = new EventQueue(transport: new RecordingTransport(), channel: $channel, finishesRequests: false);
$queue->endRequest(1.0); // the bridge of the worker ended an earlier request in its window

$lists = FakeServerInfo::demo();
$lists[ServerInfoSource::RESOURCES] = static function () use ($mode): array {
    echo 'printed by the list handler';
    if ($mode === 'stuck') {
        ob_start(null, 0, 0);
    } elseif ($mode === 'flushable') {
        ob_start(null, 0, PHP_OUTPUT_HANDLER_FLUSHABLE); // it can pass its content down, and PHP cannot remove it
    } else {
        ob_start(static fn(string $output, int $phase): string => ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0 ? throw new \RuntimeException('this buffer cannot be cleaned') : $output);
    }
    echo '[left in the bad buffer]';

    return [[], null];
};
$server = new TrackedServer('proj_' . str_repeat('o', 27), new Options(), static fn(): array => ['output-server', '1.0.0'], new Publisher($queue));

echo "response 0\n";
Heartbeat::onToolList($server, static fn(): string => 'output-server', static fn(): FakeServerInfo => new FakeServerInfo($lists, name: 'output-server', version: '1.0.0'));
$queue->endRequest(1.0);

trigger_error('a notice of the application after the run', E_USER_NOTICE);
$held = 0;
foreach ([1, 2, 3] as $response) {
    echo sprintf("response %d: %s\n", $response, str_repeat((string) $response, 5000));
    if ($mode === 'flushable') {
        ob_flush(); // the worker sends the response: the bad buffer passes it down to the buffer of the SDK below it
        $held += array_sum(array_filter(array_column(array_slice(ob_get_status(true), 0, -1), 'buffer_used'), is_int(...)));
    }
}
if ($mode === 'flushable') {
    echo 'bytes that waited below the bad buffer after a flush: ' . $held . "\n";
}
echo 'errors seen by the application: ' . json_encode($seen, JSON_THROW_ON_ERROR) . "\n";
echo 'heartbeats queued: ' . count($channel->requests) . "\n";
echo 'heartbeats that the log says were sent: ' . count(array_filter($logger->messages(), static fn(string $line): bool => str_starts_with($line, 'Sent server info heartbeat'))) . "\n";
echo 'heartbeats that the log says were skipped: ' . count(array_filter($logger->messages(), static fn(string $line): bool => str_starts_with($line, 'Skipped the server info heartbeat'))) . "\n";
