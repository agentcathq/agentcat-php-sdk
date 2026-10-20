<?php

declare(strict_types=1);

// Usage: php tests/bin/member-snapshot.php <mcp/sdk|laravel/mcp> [--diff <committed.json>]
//
// Without --diff: prints the snapshot of the installed version as JSON.
// With --diff: diffs the installed version against the committed snapshot, prints both lists (and appends them to
// $GITHUB_STEP_SUMMARY when it is set), and exits 1 when a member AgentCat reads changed.

use AgentCat\Tests\Contract\PrivateMemberManifest;
use AgentCat\Tests\Contract\Snapshot\MemberSnapshot;
use AgentCat\Tests\Contract\Snapshot\SnapshotDiff;

require __DIR__ . '/../../vendor/autoload.php';

// $_SERVER, not $argv: PHPStan on PHP 8.5 cannot prove that the global $argv exists.
$serverArgv = $_SERVER['argv'] ?? [];
$arguments = array_values(array_filter(array_slice(is_array($serverArgv) ? $serverArgv : [], 1), is_string(...)));
$library = $arguments[0] ?? '';
$committedPath = ($arguments[1] ?? '') === '--diff' ? ($arguments[2] ?? '') : null;
if (count($arguments) !== ($committedPath === null ? 1 : 3) || $library === '' || $committedPath === '') {
    fwrite(STDERR, "usage: php tests/bin/member-snapshot.php <mcp/sdk|laravel/mcp> [--diff <committed.json>]\n");
    exit(2);
}

try {
    $snapshot = MemberSnapshot::of($library);
    if ($committedPath === null) {
        echo MemberSnapshot::toJson($snapshot);
        exit(0);
    }

    $json = file_get_contents($committedPath);
    if ($json === false) {
        throw new RuntimeException('cannot read ' . $committedPath);
    }
    $committed = MemberSnapshot::fromJson($json);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$diff = SnapshotDiff::between($committed, $snapshot, PrivateMemberManifest::members());
$report = '### ' . $library . ' ' . $committed['version'] . ' → ' . $snapshot['version'] . "\n\n";
foreach (['breaking' => $diff->breaking(), 'other' => $diff->other()] as $title => $lines) {
    $report .= '#### ' . $title . ' (' . count($lines) . ")\n\n";
    foreach ($lines as $line) {
        $report .= '- ' . $line . "\n";
    }
    $report .= "\n";
}
echo $report;

$summary = getenv('GITHUB_STEP_SUMMARY');
if ($summary !== false && $summary !== '') {
    file_put_contents($summary, $report, FILE_APPEND);
}

exit($diff->breaking() === [] ? 0 : 1);
