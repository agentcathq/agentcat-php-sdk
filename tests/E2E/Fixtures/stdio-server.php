<?php

declare(strict_types=1);

use AgentCat\AgentCat;
use AgentCat\Options;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Transport\StdioTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$schema = ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']];
$builder = Server::builder()
    ->setServerInfo('e2e-stdio-server', '2.7.1')
    ->addTool(static fn(string $msg): string => 'echo: ' . $msg, 'echo', description: 'Echo a message', inputSchema: $schema)
    // Suspends the handler's fiber (a progress notification) in the middle of AgentCat's interceptor.
    ->addTool(static function (string $msg, ClientGateway $gateway): string {
        $gateway->progress(0.5, 1.0, 'half way');

        return 'done: ' . $msg;
    }, 'progress', description: 'Reports progress', inputSchema: $schema);

$collector = getenv('AGENTCAT_E2E_COLLECTOR');
// AGENTCAT_E2E_TRACK=server tracks the built server instead of the Builder (D76): the same tests run on both.
$server = getenv('AGENTCAT_E2E_TRACK') === 'server' ? $builder->build() : $builder;
AgentCat::track($server, 'proj_' . str_repeat('s', 27), new Options(apiBaseUrl: is_string($collector) && $collector !== '' ? $collector : null));

exit(($server instanceof Server ? $server : $builder->build())->run(new StdioTransport()));
