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

// TS `src/tests/setup.ts`: no test sends diagnostics unless it clears this; subprocesses inherit it. A test that turns
// them on points AGENTCAT_DIAGNOSTICS_ENDPOINT at its own closed port; this one is the fallback, so a forgotten setting
// fails closed instead of reaching otel.agentcat.com.
putenv('AGENTCAT_DISABLE_DIAGNOSTICS=true');
$_SERVER['AGENTCAT_DISABLE_DIAGNOSTICS'] = $_ENV['AGENTCAT_DISABLE_DIAGNOSTICS'] = 'true';
$agentcatClosedPort = AgentCat\Tests\Integration\Support\MockCollector::closedPortUrl();
putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=' . $agentcatClosedPort);
$_SERVER['AGENTCAT_DIAGNOSTICS_ENDPOINT'] = $_ENV['AGENTCAT_DIAGNOSTICS_ENDPOINT'] = $agentcatClosedPort;
// The event API fails closed too: MCPCAT_API_URL, the last name the SDK reads, is a closed local port, so an event of a
// test that names no collector of its own goes nowhere but 127.0.0.1. A test's own option or variable comes first.
AgentCat\Tests\Support\EventApiGuard::set();

register_shutdown_function(static function () use ($agentcatTestHome): void {
    $names = scandir($agentcatTestHome);
    foreach ($names === false ? [] : $names as $name) {
        if (is_file($agentcatTestHome . '/' . $name)) {
            unlink($agentcatTestHome . '/' . $name);
        }
    }
    rmdir($agentcatTestHome);
});
