<?php

declare(strict_types=1);

use AgentCat\Tests\Support\Symfony\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// CliTest: `mcp:server` of symfony/mcp-bundle over STDIO, in the Symfony test application (the `conformance` server
// with the STDIO transport on, and AgentCatBundle) through the framework's console application, which lets Symfony
// Console register its signal handlers. Its cache and logs are in /out. The AgentCat API is AGENTCAT_API_URL, which the
// container resolves when it starts, and which is the test's collector or a closed local port (RuntimeTestCase::env()).
$kernel = new TestKernel(
    '/out/symfony',
    agentcat: ['enabled' => 'true', 'project_id' => 'proj_' . str_repeat('e', 27)],
    mcp: ['servers' => ['conformance' => [...TestKernel::CONFORMANCE, 'transports' => ['stdio' => true]]]],
);
$application = new Application($kernel);
$application->setAutoExit(false);

exit($application->run());
