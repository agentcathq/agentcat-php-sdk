<?php

declare(strict_types=1);

// Runs the command given as arguments (the mock collector's `php -S`) until stdin reaches EOF, then stops it and
// deletes $AGENTCAT_COLLECTOR_DIR. MockCollector::stop() closes stdin, and so does the death of the test process,
// even by SIGKILL (an Infection timeout), so no collector outlives its test run. When the server exits first
// (port taken), this exits too.

$arguments = $_SERVER['argv'] ?? null;
$command = is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), is_string(...))) : [];
if ($command === []) {
    fwrite(STDERR, "usage: php watchdog.php <command> [<argument>...]\n");
    exit(2);
}

$server = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if ($server === false) {
    exit(1);
}

stream_set_blocking(STDIN, false);
while (proc_get_status($server)['running']) {
    $read = [STDIN];
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 0, 100_000) === 1) {
        fread(STDIN, 8192);
        if (feof(STDIN)) {
            proc_terminate($server);

            break;
        }
    }
}
proc_close($server);

$dir = getenv('AGENTCAT_COLLECTOR_DIR');
if (is_string($dir) && str_starts_with(basename($dir), 'agentcat-collector-') && is_dir($dir)) {
    $names = scandir($dir);
    foreach ($names === false ? [] : $names as $name) {
        if (is_file($dir . '/' . $name)) {
            unlink($dir . '/' . $name);
        }
    }
    rmdir($dir);
}
