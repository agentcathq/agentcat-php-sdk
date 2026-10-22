<?php

declare(strict_types=1);

use AgentCat\AgentCat;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A plain mcp/sdk stdio server with one tool, tracked with one line, for a PHP whose disable_functions holds the
// functions that hardened hosts disable. It calls no such function itself: the event API URL, HOME and the diagnostics
// switch come from the environment.
$schema = ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']];
$builder = Server::builder()
    ->setServerInfo('hardened-host', '1.0.0')
    ->addTool(static fn(string $msg): string => 'echo: ' . $msg, 'echo', description: 'Echo a message', inputSchema: $schema);

AgentCat::track($builder, 'proj_' . str_repeat('h', 27));

exit($builder->build()->run(new StdioTransport()));
