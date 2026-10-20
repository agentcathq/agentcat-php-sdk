<?php

declare(strict_types=1);

/**
 * The package probe (tests/bin/package-probe.sh copies this file into a fresh project; it loads nothing from the
 * repository's tests/). It tracks an mcp/sdk Builder with one `AgentCat::track()` line, serves it as a stdio
 * subprocess, runs one `initialize` and one `tools/call`, and asserts that the local collector received exactly one
 * event, for the `echo` call. It prints `probe ok <mcp/sdk version>`.
 *
 * Environment: AGENTCAT_PROBE_COLLECTOR_LOG, the file where the collector appends one JSON line per request.
 */

use Composer\InstalledVersions;

require __DIR__ . '/vendor/autoload.php';

const PROBE_TIMEOUT_SECONDS = 20.0;

function fail(string $message): never
{
    fwrite(STDERR, "probe failed: {$message}\n");

    exit(1);
}

$logFile = getenv('AGENTCAT_PROBE_COLLECTOR_LOG');
if (!is_string($logFile) || $logFile === '') {
    fail('AGENTCAT_PROBE_COLLECTOR_LOG is not set');
}

$serverScript = __DIR__ . '/probe-server.php';
file_put_contents($serverScript, <<<'PHP'
    <?php

    declare(strict_types=1);

    use AgentCat\AgentCat;
    use Mcp\Server;
    use Mcp\Server\Transport\StdioTransport;

    require __DIR__ . '/vendor/autoload.php';

    $builder = Server::builder()
        ->setServerInfo('package-probe-server', '1.0.0')
        ->addTool(
            static fn(string $msg): string => 'echo: ' . $msg,
            'echo',
            description: 'Echo a message',
            inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']],
        );

    AgentCat::track($builder, 'proj_' . str_repeat('p', 27));

    exit($builder->build()->run(new StdioTransport()));
    PHP);

$process = proc_open(
    [PHP_BINARY, $serverScript],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    __DIR__,
);
if (!is_resource($process)) {
    fail('the server did not start');
}

$deadline = microtime(true) + PROBE_TIMEOUT_SECONDS;
$buffer = '';

/** Reads stdout until the answer to request $id arrives. */
$receive = static function (int $id) use (&$pipes, &$buffer, $deadline): stdClass {
    while (true) {
        while (($newline = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline);
            $buffer = substr($buffer, $newline + 1);
            $message = json_decode($line);
            if ($message instanceof stdClass && ($message->id ?? null) === $id) {
                return $message;
            }
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            fail("no answer to request {$id} before the deadline");
        }
        $read = [$pipes[1]];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, (int) min(500_000, $remaining * 1_000_000)) === 1) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === '' || $chunk === false) {
                fail('the server closed stdout before it answered request ' . $id);
            }
            $buffer .= $chunk;
        }
    }
};
$send = static function (array $message) use (&$pipes): void {
    fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR) . "\n");
};

$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
    'protocolVersion' => '2025-06-18',
    'capabilities' => new stdClass(),
    'clientInfo' => ['name' => 'package-probe-client', 'version' => '1.0.0'],
]]);
$initialize = $receive(1);
if (!isset($initialize->result)) {
    fail('initialize did not return a result: ' . json_encode($initialize));
}
$send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'probe']]]);
$call = $receive(2);
// The first call of a session also carries AgentCat's session_id block, so look for the tool's own block.
$texts = array_map(static fn(mixed $block): mixed => $block->text ?? null, $call->result->content ?? []);
if (!in_array('echo: probe', $texts, true)) {
    fail('tools/call did not answer "echo: probe": ' . json_encode($call));
}

// stdin EOF ends the server; AgentCat delivers the queued event at shutdown.
fclose($pipes[0]);
$status = null;
while (($status = proc_get_status($process))['running']) {
    if (microtime(true) > $deadline) {
        proc_terminate($process);
        fail('the server did not exit after stdin closed');
    }
    usleep(20_000);
}
$stderr = stream_get_contents($pipes[2]);
proc_close($process);

$events = [];
foreach (is_file($logFile) ? (file($logFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
    $request = json_decode($line, true);
    $body = is_array($request) && is_string($request['body'] ?? null) ? json_decode($request['body'], true) : null;
    if (is_array($body) && isset($body['resource_name'])) {
        $events[] = $body;
    }
}
if (count($events) !== 1) {
    fail('the collector received ' . count($events) . " events, expected 1 (server stderr: {$stderr})");
}
if ($events[0]['resource_name'] !== 'echo') {
    fail('the event is not for the echo tool: ' . json_encode($events[0]));
}

$version = InstalledVersions::getPrettyVersion('mcp/sdk') ?? 'unknown';
echo "probe ok {$version}\n";
