<?php

declare(strict_types=1);

// 1 s of wall time of the 2 s max_execution_time (under ZTS the limit counts wall time), then the buffered echo server:
// its delivery after the answer runs past the limit.
$end = microtime(true) + 1.0;
while (microtime(true) < $end) {
    // busy
}

require dirname(__DIR__, 3) . '/E2E/Fixtures/buffered-server.php';
