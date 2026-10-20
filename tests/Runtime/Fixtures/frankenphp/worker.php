<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Fixtures\FrankenPhp;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\FinishRequest;
use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Options;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// The worker loop that the README documents: one server per request, AgentCat::flush() after each response. With
// AGENTCAT_E2E_BOOT_SERVER=1, one server is tracked and built at worker boot, outside any request, and serves every
// request; its AgentCat API and diagnostics then come from the container's environment. Each answer carries what the
// SDK detects in the request, read through its internal classes (hence AgentCat's namespace).
$tracked = static function (?string $collector): Builder {
    $builder = Server::builder()->setServerInfo('worker-server', '1.0.0')
        ->addTool(static fn(string $msg): string => 'echo: ' . $msg, 'echo', description: 'Echo', inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']]);
    AgentCat::track($builder, 'proj_' . str_repeat('w', 27), new Options(apiBaseUrl: $collector));

    return $builder;
};
$bootServer = getenv('AGENTCAT_E2E_BOOT_SERVER') === '1' ? $tracked(null)->build() : null;

$handler = static function () use ($tracked, $bootServer): void {
    $finish = FinishRequest::functionFor(PHP_SAPI, $_SERVER, static fn(string $name): bool => function_exists($name));
    $collector = is_string($_SERVER['HTTP_X_E2E_COLLECTOR'] ?? null) ? $_SERVER['HTTP_X_E2E_COLLECTOR'] : null;
    $server = $bootServer ?? $tracked($collector)->build();
    $factory = new Psr17Factory();
    $response = $server->run(new StreamableHttpTransport($factory->createServerRequestFromGlobals(), $factory, $factory));
    http_response_code($response->getStatusCode());
    header('X-AgentCat-Finish: ' . ($finish ?? 'null'));
    header('X-AgentCat-Wait: ' . RequestEndWait::seconds(console: false));
    header('X-E2E-Server: ' . ($bootServer instanceof Server ? 'boot' : 'request'));
    foreach ($response->getHeaders() as $name => $values) {
        header($name . ': ' . implode(', ', $values));
    }
    echo $response->getBody();
};

while (frankenphp_handle_request($handler)) {
    AgentCat::flush(RequestEndWait::WORKER_SECONDS); // D58: the documented loop waits at most 1 s per request
}
