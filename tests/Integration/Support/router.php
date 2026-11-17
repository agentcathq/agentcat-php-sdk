<?php

declare(strict_types=1);

// Mock AgentCat collector for the transport and queue tests (php -S, PHP_CLI_SERVER_WORKERS=8).
// Path /c/<scenario>/<rest>. Files in $AGENTCAT_COLLECTOR_DIR:
//   <scenario>.json  {"steps":[{"status":503,"delayMs":0,"body":"","location":"http://…"}],"default":{"status":202,"body":"{\"status\":\"accepted\"}"}}
//                    (`location`, optional, is sent as the Location header)
//   <scenario>.log   one JSON line per request (n, method, path, headers, body, start, end), written before the response.
// A server info heartbeat (path /c/<scenario>/v1/public/server-info/) is no event: it has its own counter, its own steps
// (`serverInfo` in <scenario>.json; 202 once they run out) and its own log, <scenario>.server-info.log, so the steps and
// the log of the events are the same with and without heartbeats.

$dir = getenv('AGENTCAT_COLLECTOR_DIR');
$uri = $_SERVER['REQUEST_URI'] ?? null;
if (!is_string($dir) || !is_string($uri) || preg_match('#\A/c/([a-z0-9]+)(/[^?]*)#', $uri, $match) !== 1) {
    http_response_code(404);

    return true;
}
$start = microtime(true);
$prefix = $dir . '/' . $match[1];
$serverInfo = $match[2] === '/v1/public/server-info/';
$own = $serverInfo ? $prefix . '.server-info' : $prefix;

$lock = fopen($prefix . '.lock', 'c');
if ($lock === false) {
    http_response_code(500);

    return true;
}
flock($lock, LOCK_EX);
$counter = is_file($own . '.count') ? file_get_contents($own . '.count') : '0';
$index = is_string($counter) ? (int) $counter : 0;
file_put_contents($own . '.count', (string) ($index + 1));
flock($lock, LOCK_UN);
fclose($lock);

$json = file_get_contents($prefix . '.json');
$scenario = is_string($json) ? json_decode($json, false) : null;
if ($serverInfo) {
    $steps = $scenario instanceof stdClass && is_array($scenario->serverInfo ?? null) ? $scenario->serverInfo : [];
    $step = $steps[$index] ?? (object) ['status' => 202, 'body' => '{"status":"accepted"}'];
} else {
    $steps = $scenario instanceof stdClass && is_array($scenario->steps ?? null) ? $scenario->steps : [];
    $step = $steps[$index] ?? ($scenario instanceof stdClass ? ($scenario->default ?? null) : null);
}
$status = $step instanceof stdClass && is_int($step->status ?? null) ? $step->status : 202;
$delayMs = $step instanceof stdClass && is_int($step->delayMs ?? null) ? $step->delayMs : 0;
$responseBody = $step instanceof stdClass && is_string($step->body ?? null) ? $step->body : '';
$location = $step instanceof stdClass && is_string($step->location ?? null) ? $step->location : null;

$requestBody = file_get_contents('php://input');
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}
file_put_contents($own . '.log', json_encode([
    'n' => $index,
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $match[2],
    'headers' => getallheaders(),
    'body' => is_string($requestBody) ? $requestBody : '',
    'start' => $start,
    'end' => microtime(true),
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);

http_response_code($status);
header('Content-Type: application/json');
if ($location !== null) {
    header('Location: ' . $location); // after http_response_code(): PHP sends 302 unless a 201 or 3xx is set
}
echo $responseBody;

return true;
