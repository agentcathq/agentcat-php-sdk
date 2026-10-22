<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\SignalDrain;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A CLI server whose side channel downloads large answers while SIGTERM arrives every 200 µs (a forked child sends it).
// A signal handler runs between two opcodes, so also inside a write callback of curl_multi_exec(). Each SIGTERM that
// lands there runs AgentCat's drain (SignalDrain::onSignal()), then the server's own handler, which only counts and
// returns (a graceful-stop flag): the process goes on. Before the I2 fix, such a drain re-entered the
// multi handle, and PHP crashed when the callback returned (exit 139). A SIGTERM elsewhere calls only the server's
// handler: a drain there would send the side requests itself while the next signals wait, so few would land inside a
// callback. (AgentCat installs its drain once per process; the fixture runs it on every signal of the case under test.)
// Usage: signal-in-callback.php <events-url> <side-url> <rounds>
// Prints: "survived signals=<n> nested=<drains inside curl_multi_exec()> done=<side requests answered> errors=<failed ones>"
$eventsUrl = $argv[1] ?? '';
$sideUrl = $argv[2] ?? '';
$rounds = (int) ($argv[3] ?? '0');

pcntl_signal(SIGTERM, SIG_IGN); // until the handler below is installed
$parent = posix_getpid();
$child = pcntl_fork();
if ($child === 0) {
    $until = microtime(true) + 15.0;
    while (microtime(true) < $until && posix_kill($parent, SIGTERM)) {
        usleep(200);
    }
    posix_kill(posix_getpid(), SIGKILL); // no shutdown function or destructor of the parent's objects runs here
}

$channel = new CurlHttpChannel();
$queue = new EventQueue(shutdownTimeoutSeconds: 30.0, channel: $channel);
$queue->configure($eventsUrl);
$queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'signal-in-callback')));
$drain = new SignalDrain(static fn(): EventQueue => $queue);
$signals = 0;
$nested = 0;
$earlier = static function () use (&$signals): void {
    ++$signals;
};
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function (int $signo, mixed $info = null) use ($drain, $earlier, &$nested): void {
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $index => $frame) {
        // Inside a callback, the callback's frame lies between this handler and curl_multi_exec(). Right below the
        // handler, curl_multi_exec() has returned already (PHP 8.4+ runs the signal before it pops that frame).
        if ($frame['function'] === 'curl_multi_exec' && $index > 1) {
            ++$nested;
            $drain->onSignal($signo, $earlier, $info); // delivers the event (once), then calls $earlier

            return;
        }
    }
    $earlier();
});

$done = 0;
$errors = 0;
for ($round = 0; $round < $rounds; ++$round) {
    $channel->post(new HttpRequest($sideUrl . '/r' . $round, [], ''), static function (HttpResponse $response) use (&$done, &$errors): void {
        ++$done;
        if (!$response->ok()) {
            ++$errors;
        }
    });
    $until = microtime(true) + 5.0;
    while ($channel->pending() > 0 && microtime(true) < $until) {
        $channel->pump();
        $channel->waitForActivity(0.05);
    }
}
pcntl_signal(SIGTERM, SIG_IGN);
posix_kill($child, SIGKILL);
pcntl_waitpid($child, $status);
echo sprintf("survived signals=%d nested=%d done=%d errors=%d\n", $signals, $nested, $done, $errors);
