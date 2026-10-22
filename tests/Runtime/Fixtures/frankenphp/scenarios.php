<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Fixtures\FrankenPhp;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Tests\E2E\Scenarios\Scenario;
use AgentCat\Tests\E2E\Scenarios\ScenarioToolkit;
use AgentCat\Tests\E2E\Scenarios\Sink;
use Http\Discovery\Psr17Factory;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// The long-lived lane of the end-to-end scenarios: one server, tracked and built once at worker boot with the options of
// the container's scenario, serves every request of the worker's one thread, with its sessions in memory. The loop
// calls AgentCat::flush() after each response (D58), so an event has left before the next request is served. The
// container's AGENTCAT_E2E_COLLECTOR is the AgentCat API; AGENTCAT_E2E_SINK is the sink file.
$collector = getenv('AGENTCAT_E2E_COLLECTOR');
$sink = getenv('AGENTCAT_E2E_SINK');
$builder = ScenarioToolkit::builder(new Sink(is_string($sink) && $sink !== '' ? $sink : null));
AgentCat::track($builder, ScenarioToolkit::PROJECT_ID, Scenario::from((string) getenv('AGENTCAT_E2E_SCENARIO'))->options(is_string($collector) && $collector !== '' ? $collector : null));
$server = $builder->build();
$factory = new Psr17Factory();

$handler = static function () use ($server, $factory): void {
    $response = $server->run(new StreamableHttpTransport($factory->createServerRequestFromGlobals(), $factory, $factory));
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        header($name . ': ' . implode(', ', $values));
    }
    echo $response->getBody();
};

while (frankenphp_handle_request($handler)) {
    AgentCat::flush(RequestEndWait::WORKER_SECONDS);
}
