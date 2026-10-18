<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\ExporterSink;
use AgentCat\Internal\Delivery\SignalDrain;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\RetryPolicy;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A CLI server with AgentCat's SIGTERM drain and no handler of its own (SIG_DFL). PHP runs a signal that arrives during
// curl_multi_exec() right after that call returns, inside CurlMulti::run(). A SIGUSR1 probe flood from a forked child
// finds that moment: the probe handler runs at the curl_multi_exec() line of CurlMulti.php (PHP 8.3), or right below
// the curl_multi_exec() frame (PHP 8.4+ runs it before it pops that frame), in a run() of the `transport` or the
// `channel`. The probe then queues one more event, "drain", and sends SIGTERM, which PHP runs right after the probe. The drain must deliver "drain" on the event transport and its export on the side channel,
// then SIGTERM ends the process. Prints "sigterm <time>" to STDERR.
// Usage: signal-after-exec.php <transport|channel> <large-answer-url> <events-url> <exports-url>
$mode = $argv[1] ?? '';
$largeUrl = $argv[2] ?? '';
$eventsUrl = $argv[3] ?? '';
$exportsUrl = $argv[4] ?? '';
$execLine = 0;
$source = file(dirname(__DIR__, 3) . '/src/Internal/Transport/CurlMulti.php');
foreach ($source === false ? [] : $source as $index => $line) {
    if (str_contains($line, 'curl_multi_exec($this->multi()')) {
        $execLine = $index + 1;
    }
}
$caller = $mode === 'transport' ? CurlTransport::class : CurlHttpChannel::class;

pcntl_signal(SIGUSR1, SIG_IGN); // until the probe handler below is installed
$parent = posix_getpid();
$child = pcntl_fork();
if ($child === 0) {
    $until = microtime(true) + 15.0;
    while (microtime(true) < $until && posix_kill($parent, SIGUSR1)) {
        usleep(200);
    }
    posix_kill(posix_getpid(), SIGKILL); // no shutdown function or destructor of the parent's objects runs here
}

$channel = new CurlHttpChannel();
$exports = new readonly class ($channel, $exportsUrl) implements ExporterSink {
    public function __construct(private HttpChannel $channel, private string $url) {}

    #[\Override]
    public function export(Event $event): void
    {
        $this->channel->post(new HttpRequest($this->url . '/' . $event->resourceName, [], '{}'), static function (): void {});
    }
};
$transport = new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]));
$queue = new EventQueue(transport: $transport, exporters: $exports, channel: $channel);
$queue->configure($mode === 'transport' ? $largeUrl : $eventsUrl);
(new SignalDrain(static fn(): EventQueue => $queue))->install();
$sent = false;
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, static function () use ($queue, $eventsUrl, $execLine, $caller, $child, &$sent): void {
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    $exec = ($frames[1]['function'] ?? '') === 'curl_multi_exec' ? 1 : 0; // PHP 8.4+: the returned call's frame is still there
    $at = $frames[$exec] ?? [];
    $run = $frames[$exec + 1] ?? [];
    $after = str_ends_with($at['file'] ?? '', '/CurlMulti.php') && ($at['line'] ?? 0) === $execLine && ($run['function'] ?? '') === 'run';
    if ($sent || !$after || ($frames[$exec + 2]['class'] ?? '') !== $caller) {
        return;
    }
    $sent = true;
    posix_kill($child, SIGKILL);
    $queue->configure($eventsUrl);
    $queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'drain')));
    fwrite(STDERR, sprintf("sigterm %.6F\n", microtime(true)));
    posix_kill(posix_getpid(), SIGTERM); // pending: PHP runs it right after this handler
});
for ($round = 0; $round < 20; ++$round) {
    if ($mode === 'transport') {
        $queue->add(new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: 'large-' . $round)));
    } else {
        $channel->post(new HttpRequest($largeUrl . '/large-' . $round, [], '{}'), static function (): void {});
    }
    $until = microtime(true) + 5.0;
    while ($queue->hasWork() && microtime(true) < $until) {
        $queue->pump();
        if ($mode === 'transport') {
            $transport->waitForActivity($transport->now() + 0.05);
        } else {
            $channel->waitForActivity(0.05);
        }
    }
}
pcntl_signal(SIGUSR1, SIG_IGN);
posix_kill($child, SIGKILL);
pcntl_waitpid($child, $status);
echo "not ended by SIGTERM\n";
