<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\AgentCat;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Mcp\Server;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// A server whose PHP lacks ext-curl functions (run with -d disable_functions=…): the facade must neither fail nor
// print anything (D32). Writes what it observed to the file named by the first argument.
$builder = Server::builder()->setServerInfo('missing-curl', '1.0.0');
$builder->addTool(static fn(string $msg): string => 'echo: ' . $msg, name: 'echo', description: 'Echo');
$same = AgentCat::track($builder, 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa') === $builder;
$harness = new HttpHarness($builder->build());
$harness->initialize();
$answer = HttpHarness::result($harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'ses_bbbbbbbbbbbbbbbbbbbbbbbbbbb']));
$flushed = AgentCat::flush(2.0);
AgentCat::shutdown();
file_put_contents($argv[1] ?? '', json_encode(['same' => $same, 'answer' => $answer, 'flushed' => $flushed], JSON_THROW_ON_ERROR));
