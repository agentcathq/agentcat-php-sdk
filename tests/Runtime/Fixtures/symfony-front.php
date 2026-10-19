<?php

declare(strict_types=1);

use AgentCat\Tests\Support\Symfony\TestKernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// FpmTest: the Symfony test application (symfony/mcp-bundle with the `conformance` server on /mcp/conformance, and
// AgentCatBundle) in a production kernel, through Symfony's front-controller steps. Its cache and logs are in /out. The
// first request compiles the container, and every later one reuses it, so the AgentCat API is not a literal of the
// configuration: `api_url` keeps its default, AGENTCAT_API_URL, which the container resolves at each request, and which
// is the test's collector or a closed local port (RuntimeTestCase::env()).
$kernel = new TestKernel('/out/symfony', agentcat: ['enabled' => 'true', 'project_id' => 'proj_' . str_repeat('e', 27)]);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
