<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// No test may write to the real home directory (the SDK default log lives in $HOME/agentcat.log).
// Point HOME at a per-process dir for the whole run, including mutants run by Infection. It lives in the
// git-ignored .cache/ of the repo, so a process that never reaches the cleanup below (Infection kills
// timed-out mutants) leaves nothing in /tmp. Other processes' dirs are not swept: parallel Infection
// processes share the parent dir.
$agentcatRepo = dirname(__DIR__);
$agentcatTestHome = $agentcatRepo . '/.cache/test-home/' . bin2hex(random_bytes(6));
mkdir($agentcatTestHome, 0o700, true);
putenv('HOME=' . $agentcatTestHome);
$_SERVER['HOME'] = $_ENV['HOME'] = $agentcatTestHome;

register_shutdown_function(static function () use ($agentcatTestHome): void {
    $names = scandir($agentcatTestHome);
    foreach ($names === false ? [] : $names as $name) {
        if (is_file($agentcatTestHome . '/' . $name)) {
            unlink($agentcatTestHome . '/' . $name);
        }
    }
    rmdir($agentcatTestHome);
});
