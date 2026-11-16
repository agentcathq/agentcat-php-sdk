<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

use AgentCat\Tests\E2E\Support\BuiltInServer;
use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The server info heartbeat end to end, over real HTTP to the mock collector, in two lanes.
 *
 * `php -S` sends a response only when the script ends, as mod_php and php-cgi do: AgentCat sends no heartbeat there,
 * and each request gets its normal result. The lane of a SAPI with a finish call runs as a child process for each
 * request, so each request has a new process and a new memory, as under PHP-FPM: the fixture tells the queue that the
 * SAPI finishes its requests and makes the calls of the last shutdown pass.
 */
final class ServerInfoEndToEndTest extends TestCase
{
    private const float DEADLINE_SECONDS = 5.0;

    private const string TOOLS = '["alpha","get_more_tools"]';

    private static ?MockCollector $collector = null;

    private static ?BuiltInServer $server = null;

    private static string $dir = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $home = getenv('HOME');
        self::assertIsString($home);
        self::$dir = $home . '/e2e-server-info-' . bin2hex(random_bytes(6));

        try {
            foreach (['', '/home', '/sessions', '/markers'] as $sub) {
                self::assertTrue(mkdir(self::$dir . $sub, 0o700));
            }
            self::$collector = MockCollector::start();
            self::$server = BuiltInServer::start(__DIR__ . '/Fixtures/http-server.php', [
                'AGENTCAT_ENABLED' => 'true',
                'AGENTCAT_API_URL' => MockCollector::closedPortUrl(),
                'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(),
                'HOME' => self::$dir . '/home',
                'AGENTCAT_E2E_SESSIONS' => self::$dir . '/sessions',
                'AGENTCAT_E2E_MARKERS' => self::$dir . '/markers',
                'AGENTCAT_E2E_TRACK' => 'builder',
            ]);
        } catch (\Throwable $e) {
            self::tearDownAfterClass(); // PHPUnit skips it when this method fails
            throw $e;
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
        self::$collector?->stop();
        self::$collector = null;
        FileTree::remove(self::$dir);
    }

    /** The client of `php -S` has its response only when the script ends: a heartbeat there would make it wait. */
    #[MaximumDuration(15000)]
    public function testV18WhereTheResponseEndsWithTheScriptEachRequestGetsItsResultAndNoHeartbeatIsSent(): void
    {
        $scenario = $this->collector()->scenario();
        $headers = ['X-E2E-Collector' => $scenario];
        $client = new McpHttpClient($this->server()->baseUrl());
        $client->initialize();
        $answers = [];
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $request) {
            if ($request === 'call') {
                $texts = JsonAssert::column(HttpHarness::result($client->callTool('echo', ['msg' => 'hi'], $headers)['message'])->content, 'text');
                $answers[] = end($texts);
            } else {
                $answers[] = JsonAssert::column($client->listTools($headers)->tools, 'name');
            }
        }
        $tools = ['echo', 'strict', 'boom', 'get_more_tools'];
        $this->assertSame(['echo: hi', $tools, 'echo: hi', $tools, 'echo: hi', $tools], $answers, 'each request gets its normal result');

        $events = $this->collector()->waitFor($scenario, static fn(array $requests): bool => count($requests) >= 3, self::DEADLINE_SECONDS);
        $this->assertCount(3, $events, 'the three calls gave their events: every shutdown delivery is over');
        usleep(300_000); // a heartbeat would arrive now
        $this->assertSame([], $this->collector()->serverInfo($scenario));
        $this->assertStringNotContainsString('server info heartbeat', $this->log('/home'), 'decided once: no line for each request');
    }

    /** V17 and V18 in the lane of PHP-FPM: a new process for each request. */
    #[MaximumDuration(20000)]
    public function testV18ANewProcessForEachRequestSendsForEachToolListRequestAfterItsAnswer(): void
    {
        $scenario = $this->collector()->scenario();
        foreach (['call', 'list', 'call', 'list', 'call', 'list'] as $index => $request) {
            $child = $this->child($scenario, [$request]);

            $answers = explode("\n", trim($child->getOutput()));
            $this->assertCount(1, $answers);
            $answer = json_decode($answers[0], false, flags: JSON_THROW_ON_ERROR);
            if ($request === 'list') {
                $this->assertSame(self::TOOLS, json_encode(JsonAssert::column(JsonAssert::at($answer, 'result', 'tools'), 'name')));
            } else {
                $this->assertStringContainsString('alpha ran', $answers[0]);
            }
            $this->assertSame('before any list handler', file_get_contents(self::$dir . '/markers/answered'), 'the client has its answer before a list handler starts');
            $this->assertCount(intdiv($index + 1, 2), $this->collector()->serverInfo($scenario), 'one for each list request, none for a tool call');
        }

        $heartbeats = $this->collector()->serverInfo($scenario);
        $this->assertCount(3, $heartbeats);
        $this->assertCount(3, $this->collector()->requests($scenario), 'the three events of the calls, and no heartbeat among them');
        foreach ($heartbeats as $heartbeat) {
            $this->assertSame(['POST', '/v1/public/server-info/'], [JsonAssert::at($heartbeat, 'method'), JsonAssert::at($heartbeat, 'path')]);
            $sent = array_change_key_case(get_object_vars(JsonAssert::object(JsonAssert::at($heartbeat, 'headers'))));
            $this->assertSame('application/json', $sent['content-type'] ?? null);
            $this->assertArrayNotHasKey('authorization', $sent);
            $agent = $sent['user-agent'] ?? null;
            $this->assertIsString($agent);
            $this->assertStringStartsWith('agentcat-php/', $agent);
            $body = json_decode(MockCollector::body($heartbeat), false, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($body));
            $this->assertSame(['proj_' . str_repeat('c', 27), 'child-server', '1.0.0'], [JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'server_version')]);
            $this->assertSame(self::TOOLS, json_encode(JsonAssert::column(JsonAssert::at($body, 'features', 'tools'), 'name')));
            $this->assertSame([], JsonAssert::at($body, 'features', 'resources'));
        }
        $event = json_decode(MockCollector::body(JsonAssert::object($this->collector()->requests($scenario)[0] ?? null)), false, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['child-server', '1.0.0'], [JsonAssert::at($event, 'server_name'), JsonAssert::at($event, 'server_version')], 'V15: the heartbeat names the server as the events do');
    }

    #[MaximumDuration(15000)]
    public function testV13After413TheSmallerBodyFollowsOverRealHttp(): void
    {
        $scenario = $this->collector()->scenario(serverInfo: [['status' => 413, 'body' => '{"error":"too large"}']]);

        $this->child($scenario, ['list']);

        $heartbeats = $this->collector()->serverInfo($scenario);
        $this->assertCount(2, $heartbeats);
        $first = json_decode(MockCollector::body($heartbeats[0]), false, flags: JSON_THROW_ON_ERROR);
        $second = json_decode(MockCollector::body($heartbeats[1]), false, flags: JSON_THROW_ON_ERROR);
        $this->assertObjectHasProperty('inputSchema', JsonAssert::object(JsonAssert::at($first, 'features', 'tools', 0)));
        foreach (JsonAssert::list(JsonAssert::at($second, 'features', 'tools')) as $index => $tool) {
            $this->assertObjectNotHasProperty('inputSchema', JsonAssert::object($tool));
            $this->assertSame([JsonAssert::at($first, 'features', 'tools', $index, 'name'), JsonAssert::at($first, 'features', 'tools', $index, 'description')], [JsonAssert::at($tool, 'name'), JsonAssert::at($tool, 'description')]);
        }
    }

    /** V10 with a real process: what the customer's code warns, prints and throws reaches neither stream. */
    #[MaximumDuration(15000)]
    public function testV10ANoisyAndFailingListHandlerAndRedactorLeaveBothStreamsOfTheProcessClean(): void
    {
        $scenario = $this->collector()->scenario();

        $child = $this->child($scenario, ['list'], noisy: true);

        $this->assertSame('', $child->getErrorOutput(), 'standard error is empty');
        $lines = explode("\n", trim($child->getOutput()));
        $this->assertCount(1, $lines, 'standard output holds the protocol answer only');
        $answer = json_decode($lines[0], false, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2.0', JsonAssert::at($answer, 'jsonrpc'));
        $this->assertSame(self::TOOLS, json_encode(JsonAssert::column(JsonAssert::at($answer, 'result', 'tools'), 'name')));
        $this->assertSame("ran\n", file_get_contents(self::$dir . '/markers/list-handler'));
        $heartbeats = $this->collector()->serverInfo($scenario);
        $this->assertCount(1, $heartbeats, 'the failed list is left out, and the heartbeat goes out');
        $this->assertObjectNotHasProperty('resources', JsonAssert::object(JsonAssert::at(json_decode(MockCollector::body($heartbeats[0]), false, flags: JSON_THROW_ON_ERROR), 'features')));
        $log = $this->log('/child-home');
        $this->assertStringContainsString('| without resources (RuntimeException)', $log);
        $this->assertStringNotContainsString('the list handler failed', $log, 'no message of the customer\'s code is logged');
    }

    /**
     * Runs the fixture of the finish lane for these requests, in a new process, and waits for its end.
     *
     * @param list<string> $requests each `list` or `call`
     */
    private function child(string $scenario, array $requests, bool $noisy = false): Process
    {
        $home = self::$dir . '/child-home';
        if (!is_dir($home)) {
            $this->assertTrue(mkdir($home, 0o700));
        }
        foreach (['list-handler', 'answered'] as $marker) { // what the child before left
            if (is_file(self::$dir . '/markers/' . $marker)) {
                unlink(self::$dir . '/markers/' . $marker);
            }
        }
        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'log_errors=0', __DIR__ . '/Fixtures/server-info-finish.php', ...$requests],
            null,
            [
                'AGENTCAT_ENABLED' => 'true',
                'AGENTCAT_API_URL' => MockCollector::closedPortUrl(),
                'HOME' => $home,
                'SERVER_INFO_COLLECTOR' => $scenario,
                'SERVER_INFO_MARKERS' => self::$dir . '/markers',
                'SERVER_INFO_NOISY' => $noisy ? '1' : '0',
            ],
            null,
            20.0,
        );
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        if (!$noisy) {
            $this->assertSame('', $process->getErrorOutput());
        }

        return $process;
    }

    /** The agentcat.log under a home directory of this test class, so far. */
    private function log(string $home): string
    {
        $path = self::$dir . $home . '/agentcat.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }

    private function server(): BuiltInServer
    {
        $this->assertInstanceOf(BuiltInServer::class, self::$server);

        return self::$server;
    }
}
