<?php

declare(strict_types=1);

// FpmTest: a request that holds a native PHP session (files in the container's /tmp), whose lock a later request of the
// same session waits for until this one writes the session. The count of requests in the session goes out as a header,
// so the test knows that both requests shared one session. Then the tracked echo server of buffered-server.php.
session_start();
$count = (is_int($_SESSION['n'] ?? null) ? $_SESSION['n'] : 0) + 1;
$_SESSION['n'] = $count;
header('X-E2E-Session-Count: ' . $count);

require dirname(__DIR__, 2) . '/E2E/Fixtures/buffered-server.php';
