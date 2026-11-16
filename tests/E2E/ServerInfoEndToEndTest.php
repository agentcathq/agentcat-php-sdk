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

/**
 * The server info heartbeat end to end, where the SDK keeps no memory between requests: `php -S` runs a new PHP
 * request, with new static memory, for each HTTP request, as PHP-FPM does. The fixture sends its answer with a
 * `Content-Length` and flushes it, so the client has it before the shutdown function delivers; the heartbeat goes over
 * real HTTP to the mock collector.
 */
final class ServerInfoEndToEndTest extends TestCase
{
    private const float DEADLINE_SECONDS = 5.0;

    private const string SKIPPED = 'Skipped the server info heartbeat: the client does not have its response yet';

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

    #[MaximumDuration(15000)]
    public function testV18ANewProcessMemoryForEachRequestSendsOneHeartbeatForEachToolListRequest(): void
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
        $this->assertCount(3, $events, 'the three calls gave their events: every shutdown delivery of a call is over');
        $heartbeats = $this->heartbeats($scenario, 3);
        usleep(300_000); // a heartbeat too many would arrive now
        $this->assertCount(3, $this->collector()->serverInfo($scenario), 'one for each list request, none for a tool call');

        foreach ($heartbeats as $heartbeat) {
            $this->assertSame(['POST', '/v1/public/server-info/'], [JsonAssert::at($heartbeat, 'method'), JsonAssert::at($heartbeat, 'path')]);
            $sent = array_change_key_case(get_object_vars(JsonAssert::object(JsonAssert::at($heartbeat, 'headers'))));
            $this->assertSame('application/json', $sent['content-type'] ?? null);
            $this->assertArrayNotHasKey('authorization', $sent);
            $agent = $sent['user-agent'] ?? null;
            $this->assertIsString($agent);
            $this->assertStringStartsWith('agentcat-php/', $agent);
            $body = json_decode(MockCollector::body($heartbeat), false, flags: JSON_THROW_ON_ERROR);
            $this->assertInstanceOf(\stdClass::class, $body);
            $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($body));
            $this->assertSame(['proj_' . str_repeat('e', 27), 'e2e-http-server', '3.1.4'], [JsonAssert::at($body, 'project_id'), JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'server_version')]);
            $this->assertSame($tools, JsonAssert::column(JsonAssert::at($body, 'features', 'tools'), 'name'));
            $this->assertSame(['msg', 'session_id', 'context'], JsonAssert::keys(JsonAssert::at($body, 'features', 'tools', 0, 'inputSchema', 'properties')));
        }
        $event = json_decode(MockCollector::body($events[0]), false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $event);
        $this->assertSame(['e2e-http-server', '3.1.4'], [JsonAssert::at($event, 'server_name'), JsonAssert::at($event, 'server_version')], 'V15: the heartbeat names the server as the events do');
    }

    #[MaximumDuration(15000)]
    public function testV13After413TheSmallerBodyFollowsOverRealHttp(): void
    {
        $scenario = $this->collector()->scenario(serverInfo: [['status' => 413, 'body' => '{"error":"too large"}']]);
        $client = new McpHttpClient($this->server()->baseUrl());
        $client->initialize();

        $client->listTools(['X-E2E-Collector' => $scenario]);

        $heartbeats = $this->heartbeats($scenario, 2);
        $first = json_decode(MockCollector::body(JsonAssert::object($heartbeats[0] ?? null)), false, flags: JSON_THROW_ON_ERROR);
        $second = json_decode(MockCollector::body(JsonAssert::object($heartbeats[1] ?? null)), false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $first);
        $this->assertInstanceOf(\stdClass::class, $second);
        $this->assertObjectHasProperty('inputSchema', JsonAssert::object(JsonAssert::at($first, 'features', 'tools', 0)));
        foreach (JsonAssert::list(JsonAssert::at($second, 'features', 'tools')) as $index => $tool) {
            $this->assertObjectNotHasProperty('inputSchema', JsonAssert::object($tool));
            $this->assertSame([JsonAssert::at($first, 'features', 'tools', $index, 'name'), JsonAssert::at($first, 'features', 'tools', $index, 'description')], [JsonAssert::at($tool, 'name'), JsonAssert::at($tool, 'description')]);
        }
    }

    /** `php -S` sends an answer without a length only when the script ends: the client would wait for the heartbeat. */
    #[MaximumDuration(15000)]
    public function testWhereTheClientWaitsForTheEndOfTheScriptNoHeartbeatIsSent(): void
    {
        $scenario = $this->collector()->scenario();
        $client = new McpHttpClient($this->server()->baseUrl());
        $client->initialize();

        $tools = $client->listTools(['X-E2E-Collector' => $scenario, 'X-E2E-No-Length' => '1'])->tools;

        $this->assertCount(4, JsonAssert::list($tools));
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        while (!str_contains($this->log(), self::SKIPPED) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $this->assertStringContainsString(self::SKIPPED, $this->log());
        $this->assertSame([], $this->collector()->serverInfo($scenario));
    }

    /**
     * Waits for `$count` heartbeats of the scenario.
     *
     * @return list<\stdClass>
     */
    private function heartbeats(string $scenario, int $count): array
    {
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $heartbeats = $this->collector()->serverInfo($scenario);
        while (count($heartbeats) < $count && microtime(true) < $deadline) {
            usleep(20_000);
            $heartbeats = $this->collector()->serverInfo($scenario);
        }
        $this->assertCount($count, $heartbeats, $this->log());

        return $heartbeats;
    }

    /** The server's agentcat.log so far. */
    private function log(): string
    {
        $path = self::$dir . '/home/agentcat.log';

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
