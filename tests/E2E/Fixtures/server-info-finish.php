<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E\Fixtures;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Options;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Server;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// One request of a per-request SAPI with a finish call, as a command: the queue is told that the SAPI finishes its
// requests, the answer goes to STDOUT, and the script then makes the two calls of the last shutdown pass. The heartbeat
// goes over real HTTP to $SERVER_INFO_COLLECTOR. With SERVER_INFO_NOISY=1 the customer's code warns, prints and fails.
// Arguments: the requests to answer, each `list` or `call`.
$queue = new EventQueue(finishesRequests: true);
EventQueue::setGlobal($queue);
$noisy = getenv('SERVER_INFO_NOISY') === '1';
$markers = (string) getenv('SERVER_INFO_MARKERS');

$resources = new CustomerHandler(ListResourcesRequest::class, static function () use ($noisy, $markers): ListResourcesResult {
    file_put_contents($markers . '/list-handler', "ran\n", FILE_APPEND);
    if ($noisy) {
        trigger_error('a warning of the list handler', E_USER_WARNING);
        echo 'printed by the list handler';

        throw new \RuntimeException('the list handler failed');
    }

    return new ListResourcesResult([]);
});
$builder = Server::builder()
    ->setServerInfo('child-server', '1.0.0')
    ->setSession()
    ->addTool(static fn(): string => 'alpha ran', 'alpha', description: 'The tool')
    ->addRequestHandler($resources);
AgentCat::track($builder, 'proj_' . str_repeat('c', 27), new Options(
    redactSensitiveInformation: $noisy ? static function (string $text): string {
        trigger_error('a notice of the redaction function', E_USER_NOTICE);
        echo 'printed by the redaction function';

        return $text;
    } : null,
    apiBaseUrl: (string) getenv('SERVER_INFO_COLLECTOR'),
));
$harness = new HttpHarness($builder->build());
$harness->initialize();
foreach (array_slice($argv, 1) as $request) {
    $answer = $request === 'list' ? $harness->request('tools/list') : $harness->callTool('alpha', []);
    fwrite(STDOUT, json_encode($answer, JSON_THROW_ON_ERROR) . "\n");
}
file_put_contents($markers . '/answered', is_file($markers . '/list-handler') ? 'after a list handler' : 'before any list handler');

$queue->responseSent();
$queue->finalShutdown();
