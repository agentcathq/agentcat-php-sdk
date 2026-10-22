<?php

declare(strict_types=1);

// A request that ignores SIGTERM and then hangs for 30 s, after it answered: BuiltInServer::stop() must still end it.
// It writes its PID to $AGENTCAT_E2E_MARKERS/pid.

pcntl_signal(SIGTERM, SIG_IGN);
file_put_contents(getenv('AGENTCAT_E2E_MARKERS') . '/pid', (string) getmypid());
$body = 'hanging';
header('Content-Length: ' . strlen($body));
echo $body;
flush();
$until = microtime(true) + 30.0;
while (microtime(true) < $until) {
    usleep(10_000);
}
