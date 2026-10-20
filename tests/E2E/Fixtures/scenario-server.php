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
$header = static fn(string $name): ?string => is_string($_SERVER['HTTP_' . $name] ?? null) ? $_SERVER['HTTP_' . $name] : null;
$scenario = Scenario::tryFrom($header('X_E2E_SCENARIO') ?? '') ?? Scenario::BaselineDefaults;
$sink = $header('X_E2E_SINK');
$pageSize = $header('X_E2E_PAGE_SIZE');

$builder = ScenarioToolkit::builder(new Sink($sink !== null && preg_match('/\A[0-9a-f]{16}\z/', $sink) === 1 ? getenv('AGENTCAT_E2E_SINKS') . '/' . $sink . '.jsonl' : null))
    ->setSession(new FileSessionStore((string) getenv('AGENTCAT_E2E_SESSIONS')));
if ($pageSize !== null && preg_match('/\A[1-9][0-9]?\z/', $pageSize) === 1) {
    $builder->setPaginationLimit((int) $pageSize);
}
AgentCat::track($builder, ScenarioToolkit::PROJECT_ID, $scenario->options($header('X_E2E_COLLECTOR')));

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
