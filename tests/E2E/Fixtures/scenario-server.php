<?php

declare(strict_types=1);

use AgentCat\AgentCat;
use AgentCat\Tests\E2E\Scenarios\Scenario;
use AgentCat\Tests\E2E\Scenarios\ScenarioToolkit;
use AgentCat\Tests\E2E\Scenarios\Sink;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// The per-request lane of the end-to-end scenarios: every request builds and tracks a fresh server, like PHP-FPM; the
// handshake lives in a FileSessionStore. The request headers pick the scenario (its options), the AgentCat API (the
// test's collector scenario; without one, the closed port of AGENTCAT_API_URL), the sink file and mcp/sdk's page size.
// A request without a known scenario, or with a collector off this host or a malformed sink or page size, gets HTTP
// 500 and a JSON-RPC error that names the header: the test fails with that message instead of running other options.
$header = static fn(string $name): ?string => is_string($_SERVER['HTTP_' . $name] ?? null) ? $_SERVER['HTTP_' . $name] : null;
$scenario = Scenario::tryFrom($header('X_E2E_SCENARIO') ?? '');
$collector = $header('X_E2E_COLLECTOR');
$sink = $header('X_E2E_SINK');
$pageSize = $header('X_E2E_PAGE_SIZE');
$quote = static fn(?string $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$refusal = match (true) {
    !$scenario instanceof Scenario => 'unknown X-E2E-Scenario ' . $quote($header('X_E2E_SCENARIO')),
    $collector !== null && preg_match('#\Ahttp://(?:127\.0\.0\.1|localhost):[0-9]{1,5}(?:/|\z)#', $collector) !== 1 => 'X-E2E-Collector must be on http://127.0.0.1 or http://localhost, got ' . $quote($collector),
    $sink !== null && preg_match('/\A[0-9a-f]{16}\z/', $sink) !== 1 => 'malformed X-E2E-Sink ' . $quote($sink),
    $pageSize !== null && preg_match('/\A[1-9][0-9]?\z/', $pageSize) !== 1 => 'malformed X-E2E-Page-Size ' . $quote($pageSize),
    default => null,
};
if ($refusal !== null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'scenario-server: ' . $refusal]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    exit;
}

$builder = ScenarioToolkit::builder(new Sink($sink !== null ? getenv('AGENTCAT_E2E_SINKS') . '/' . $sink . '.jsonl' : null))
    ->setSession(new FileSessionStore((string) getenv('AGENTCAT_E2E_SESSIONS')));
if ($pageSize !== null) {
    $builder->setPaginationLimit((int) $pageSize);
}
AgentCat::track($builder, ScenarioToolkit::PROJECT_ID, $scenario->options($collector));

$factory = new Psr17Factory();
$response = $builder->build()->run(new StreamableHttpTransport($factory->createServerRequestFromGlobals(), $factory, $factory));
$body = (string) $response->getBody();
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
header('Content-Length: ' . strlen($body)); // the client can finish reading before the shutdown delivery runs
echo $body;
flush();
