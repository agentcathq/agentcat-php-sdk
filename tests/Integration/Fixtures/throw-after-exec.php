<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\CurlMulti;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\RetryPolicy;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A CLI server whose own signal handler throws (an alarm-based tool timeout) while AgentCat sends events. PHP runs a
// signal that arrived during curl_multi_exec() right after that call returns, inside CurlMulti::run(). A SIGUSR1 probe
// flood from a forked child finds that moment in the event transport's run() (at the curl_multi_exec() line on PHP 8.3,
// right below the returned call's frame on PHP 8.4+). There the handler queues one more event and throws, up to
// <throws> times. On PHP 8.3 a throw at that moment corrupted the frame when the exec call was nested in another call's
// arguments (a segfault, a hang or an out-of-memory error). The transport swallows the throwable (D32); the server
// goes on, delivers every event and exits 0.
// Usage: throw-after-exec.php <large-answer-url> <throws>
// Prints: "throws=<n> left=<events still queued or in flight>"
$largeUrl = $argv[1] ?? '';
$wanted = (int) ($argv[2] ?? '5');
$execLine = 0;
$source = file(dirname(__DIR__, 3) . '/src/Internal/Transport/CurlMulti.php');
foreach ($source === false ? [] : $source as $index => $line) {
    if (str_contains($line, 'curl_multi_exec($this->multi()')) {
        $execLine = $index + 1;
    }
}

pcntl_signal(SIGUSR1, SIG_IGN); // until the probe handler below is installed
$parent = posix_getpid();
$child = pcntl_fork();
if ($child === 0) {
    $until = microtime(true) + 15.0;
    while (microtime(true) < $until && posix_kill($parent, SIGUSR1)) {
        usleep(100);
    }
    posix_kill(posix_getpid(), SIGKILL); // no shutdown function or destructor of the parent's objects runs here
}

$transport = new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]));
$queue = new EventQueue(transport: $transport);
$queue->configure($largeUrl);
$throws = 0;
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function () use ($queue, $execLine, $wanted, &$throws): void {
    if ($throws >= $wanted) {
        return;
    }
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $exec = ($frames[1]['function'] ?? '') === 'curl_multi_exec' ? 1 : 0; // PHP 8.4+: the returned call's frame is still there
    $at = $frames[$exec] ?? [];
    $run = $frames[$exec + 1] ?? [];
    $after = str_ends_with($at['file'] ?? '', '/CurlMulti.php') && ($at['line'] ?? 0) === $execLine
        && ($run['function'] ?? '') === 'run' && ($run['class'] ?? '') === CurlMulti::class;
    if (!$after || ($frames[$exec + 2]['class'] ?? '') !== CurlTransport::class) {
        return;
    }
    ++$throws;
    $queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'thrown-' . $throws)));

    throw new \RuntimeException('a tool timeout from a signal handler');
});
for ($round = 0; $round < 40 && $throws < $wanted; ++$round) {
    $queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'large-' . $round)));
    $until = microtime(true) + 5.0;
    while ($queue->hasWork() && microtime(true) < $until) {
        $queue->pump();
        $transport->waitForActivity($transport->now() + 0.05);
    }
}
pcntl_signal(SIGUSR1, SIG_IGN);
posix_kill($child, SIGKILL);
pcntl_waitpid($child, $status);
$queue->flush(5.0);
echo sprintf("throws=%d left=%d\n", $throws, $queue->stats()['queueLength'] + $queue->stats()['activeRequests']);
