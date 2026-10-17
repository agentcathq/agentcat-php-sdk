<?php

declare(strict_types=1);

use AgentCat\AgentCat;
use AgentCat\Identity;
use AgentCat\Options;
use AgentCat\ToolCall;
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Per-test settings travel in request headers, so one php -S process serves every test of a class.
$collector = is_string($_SERVER['HTTP_X_E2E_COLLECTOR'] ?? null) ? $_SERVER['HTTP_X_E2E_COLLECTOR'] : null;
$markers = (string) getenv('AGENTCAT_E2E_MARKERS');
if (($_SERVER['HTTP_X_E2E_RECORD_ACCEPT'] ?? null) === '1') {
    // The Accept value this SAPI gave PHP for repeated Accept lines; the test compares the event with it.
    file_put_contents($markers . '/accept', is_string($_SERVER['HTTP_ACCEPT'] ?? null) ? $_SERVER['HTTP_ACCEPT'] : '');
}

$builder = Server::builder()
    ->setServerInfo('e2e-http-server', '3.1.4')
    ->setSession(new FileSessionStore((string) getenv('AGENTCAT_E2E_SESSIONS')))
    ->addTool(static fn(string $msg): string => 'echo: ' . $msg, 'echo', description: 'Echo a message', inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']])
    ->addTool(static fn(string $msg): string => 'strict: ' . $msg, 'strict', description: 'Strict echo', inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg'], 'additionalProperties' => false])
    ->addTool(static function (): string {
        throw new \RuntimeException('e2e boom');
    }, 'boom', description: 'Always fails', inputSchema: ['type' => 'object', 'properties' => []]);

AgentCat::track($builder, 'proj_' . str_repeat('e', 27), new Options(
    identify: static function (ToolCall $call) use ($markers): Identity {
        if (($call->headers['x-e2e-identify-sleep'] ?? '') === '1') {
            usleep(1_000_000);
            file_put_contents($markers . '/identify', sprintf('%.6F', microtime(true)));
        }

        return new Identity('e2e-user', 'E2E User', ['plan' => 'pro']);
    },
    apiBaseUrl: $collector,
));

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
