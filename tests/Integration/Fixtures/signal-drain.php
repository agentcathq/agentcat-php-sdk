<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Pipeline\QueuedEvent;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A long-running CLI server with one event in the global queue.
// Usage: signal-drain.php <collector-url> <mode> <marker>
//   default:    no SIGTERM handler of its own. It sends itself SIGTERM: AgentCat delivers the event, then SIGTERM
//               ends the process.
//   chain:      its own SIGTERM handler first, typed the way PHP calls it (int $signo, array $info). AgentCat
//               delivers the event, then calls that handler, which writes "customer", the time and $info['signo'] to
//               <marker>; the script goes on and prints "done".
//   chain-exit: its own SIGTERM handler calls exit(0). It prints "signaled" just before it sends itself SIGTERM.
//   twice:      like default, but it prints "signaled" just before the SIGTERM, so that the test can send another.
//   disabled:   disableSignalHandlers is on: it calls EventQueue::global()->disableSignalHandlers() before the event, then
//               sends itself SIGTERM. Nothing delivers; SIGTERM ends the process at once.
//   disabled-shutdown: like disabled, but it prints "ending" and ends normally: the shutdown function delivers the event.
//   shutdown:   no signal of its own: it prints "ending" and ends, so that the shutdown function delivers the event.
//   outside:    it prints "ready" and waits for the SIGTERM of the test, so that it also runs where a host disabled
//               posix_getpid().
$baseUrl = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$markerFile = $argv[3] ?? '';
if ($mode === 'chain') {
    pcntl_signal(SIGTERM, static function (int $signo, array $info) use ($markerFile): void {
        file_put_contents($markerFile, sprintf("customer\n%.6F\n%s", microtime(true), var_export($info['signo'] ?? null, true)));
    });
}
if ($mode === 'chain-exit') {
    pcntl_signal(SIGTERM, static function (): never {
        exit(0);
    });
}
$queue = EventQueue::global();
if ($mode === 'disabled' || $mode === 'disabled-shutdown') {
    $queue->disableSignalHandlers();
}
$queue->configure($baseUrl);
$queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'signal-drain')));
if ($mode === 'shutdown' || $mode === 'disabled-shutdown') {
    echo "ending\n";

    exit(0);
}
if ($mode === 'chain-exit' || $mode === 'twice') {
    echo "signaled\n";
}
if ($mode === 'outside') {
    echo "ready\n";
} else {
    posix_kill(posix_getpid(), SIGTERM);
}
if ($mode === 'chain') {
    usleep(200_000);
    echo "done\n";

    exit(0);
}
// A server loop that sleeps: SIGTERM must end it long before the 5 s are over.
for ($step = 0; $step < 100; ++$step) {
    usleep(50_000);
}
