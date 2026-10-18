<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A signal handler that calls exit() inside a write callback of the side channel (a framework's SIGTERM handler often
// exits; the seam stands in for it). PHP skips every finally block on exit(), so the channel's re-entrancy guard must
// still be cleared: the shutdown function then sends one more request on the same channel.
// Usage: exit-in-callback.php <side-url>
// Prints "exit", then from the shutdown function "after: <status> <error or ->" and "pending: <n>".
$url = $argv[1] ?? '';
$exited = false;
$channel = new CurlHttpChannel(onWrite: static function () use (&$exited): void {
    if (!$exited) {
        $exited = true;
        echo "exit\n";

        exit(0);
    }
});
register_shutdown_function(static function () use ($channel, $url): void {
    $channel->post(new HttpRequest($url . '/after', [], ''), static function (HttpResponse $response): void {
        echo 'after: ', $response->status, ' ', $response->error ?? '-', "\n";
    });
    $until = microtime(true) + 3.0;
    while ($channel->pending() > 0 && microtime(true) < $until) {
        $channel->pump();
        $channel->waitForActivity(0.05);
    }
    echo 'pending: ', $channel->pending(), "\n";
});
$channel->post(new HttpRequest($url . '/first', [], ''), static function (): void {});
$until = microtime(true) + 3.0;
while ($channel->pending() > 0 && microtime(true) < $until) {
    $channel->pump();
    $channel->waitForActivity(0.05);
}
echo "not reached\n";
