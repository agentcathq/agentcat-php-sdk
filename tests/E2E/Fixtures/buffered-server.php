<?php

declare(strict_types=1);

use AgentCat\AgentCat;
use AgentCat\Options;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A 2026-07-28 server per request. The body stays in PHP's output buffer, with a Content-Length, and no flush().
$collector = is_string($_SERVER['HTTP_X_E2E_COLLECTOR'] ?? null) ? $_SERVER['HTTP_X_E2E_COLLECTOR'] : null;
$builder = Server::builder()
    ->setServerInfo('e2e-buffered-server', '1.0.0')
    ->addTool(static fn(string $msg): string => 'echo: ' . $msg, 'echo', description: 'Echo', inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']]);
AgentCat::track($builder, 'proj_' . str_repeat('e', 27), new Options(apiBaseUrl: $collector));

$factory = new Psr17Factory();
$response = $builder->build()->run(new StreamableHttpTransport($factory->createServerRequestFromGlobals(), $factory, $factory));
$body = (string) $response->getBody();
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}
header('Content-Length: ' . strlen($body));
echo $body; // no flush(): with output_buffering=4096 the body waits in PHP's buffer until AgentCat sends it
