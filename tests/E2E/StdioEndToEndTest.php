<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Json\Json;
use AgentCat\Tests\E2E\Support\StdioMcpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\McpSdkVersion;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * A real mcp/sdk stdio server, tracked with one `AgentCat::track()` line (`Fixtures/stdio-server.php`), driven with
 * raw JSON-RPC over its stdin and stdout. One long-lived process serves the whole MCP session, like a server that an
 * MCP host starts. AgentCat delivers between requests (the pump at handler entry), at stdin EOF (the shutdown
 * function) and at SIGTERM (the signal drain).
 */
final class StdioEndToEndTest extends TestCase
{
    private const string HANDSHAKE_PROTOCOL_VERSION = '2025-06-18';

    private const string PROJECT_ID = 'proj_sssssssssssssssssssssssssss';

    /** The 5 s shutdown budget, plus room for a slow runner. */
    private const float EOF_EXIT_SECONDS = 6.0;

    /** Review Focus 3: the 5 s shutdown budget with every delivery slot held by a collector that answers after 8 s. */
    private const float SLOW_COLLECTOR_EXIT_SECONDS = 6.5;

    private const float SIGTERM_EXIT_SECONDS = 3.0;

    private const float COLLECTOR_DEADLINE_SECONDS = 5.0;

    /** How long the delivery test waits for the collector after each answer before it sends the next call. */
    private const float BETWEEN_CALLS_SECONDS = 0.5;

    private const int MAX_CALLS_BEFORE_DELIVERY = 10;

    private const int SIGTERM = 15;

    private static ?MockCollector $collector = null;

    /** The HOME (agentcat.log) of every server of this class, under the test HOME (Ruling R8). */
    private static string $dir = '';

    /** The collector scenario of the current test. */
    private string $scenario = '';

    /** The HOME of the current test's server. */
    private string $home = '';

    private ?StdioMcpClient $client = null;

    private int $nextId = 1;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $home = getenv('HOME');
        self::assertIsString($home);
        self::$dir = $home . '/e2e-stdio-' . bin2hex(random_bytes(6));

        try {
            self::assertTrue(mkdir(self::$dir, 0o700));
            self::$collector = MockCollector::start();
        } catch (\Throwable $e) {
            self::tearDownAfterClass(); // PHPUnit skips it when this method fails

            throw $e;
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
        FileTree::remove(self::$dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->client?->process()->stop(0);
        $this->client = null;
    }

    /** M6: one stdio session through the handshake; both events reach the collector by the time stdin closes. */
    #[Group('ac-02-53')]
    #[MaximumDuration(15000)]
    public function testAHandshakeSessionIsTrackedAndDeliveredAtEof(): void
    {
        $client = $this->start();
        $this->handshake($client);
        $tools = $this->resultOf($client, 'tools/list', [])->tools ?? null;
        $this->assertIsList($tools);
        $this->assertSame('echo', JsonAssert::at($tools, 0, 'name'));

        $first = $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'one', 'session_id' => 'start']]);
        $sessionId = $this->issuedSessionId($first);
        $this->assertSame([$this->issued($sessionId), 'echo: one'], $this->texts($first));
        $second = $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'two', 'session_id' => $sessionId]]);
        $this->assertSame(['echo: two'], $this->texts($second), 'the issued session_id is recognized: no new block');

        $this->assertExitsAtEof($client);
        $events = $this->events(2);
        foreach ($events as $event) {
            $this->assertSame('e2e-stdio-server', JsonAssert::at($event, 'server_name'));
            $this->assertSame('2.7.1', JsonAssert::at($event, 'server_version'));
            $this->assertSame('stdio-client', JsonAssert::at($event, 'client_name'));
            $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
            $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
        }
        $this->assertEqualsCanonicalizing(['one', 'two'], array_map(
            static fn(\stdClass $event): mixed => JsonAssert::at($event, 'parameters', 'request', 'params', 'arguments', 'msg'),
            $events,
        ));
    }

    /** No SDK byte on STDOUT or STDERR: the log lines go to `$HOME/agentcat.log`. */
    #[Group('ac-03-37')]
    #[Group('ac-01-87')]
    #[MaximumDuration(15000)]
    public function testStdoutCarriesOnlyJsonRpcAndTheSdkLogsToItsFile(): void
    {
        $client = $this->start();
        $this->handshake($client);
        $this->resultOf($client, 'tools/list', []);
        $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => 'start']]);
        $this->resultOf($client, 'tools/call', ['name' => 'progress', 'arguments' => ['msg' => 'hi', 'session_id' => 'start'], '_meta' => ['progressToken' => 'p0']]);

        $this->assertExitsAtEof($client);
        $this->events(2);
        $this->assertStdoutIsOnlyJsonRpc($client);
        $this->assertSame('', $client->process()->getErrorOutput());
        $log = $this->log();
        $this->assertStringContainsString('AgentCat setup started | project ' . self::PROJECT_ID . ' | sdk mcp-sdk/builder | signals ', $log);
        $this->assertStringContainsString('AgentCat setup complete | project ' . self::PROJECT_ID . ' | tracing=true context=true reportMissing=true exporters=0', $log);
    }

    /** The tool's progress notification suspends the handler's fiber in the middle of AgentCat's interceptor. */
    #[MaximumDuration(15000)]
    public function testAFiberSuspendedInsideTheInterceptorIsAnsweredAndTrackedOnce(): void
    {
        $client = $this->start();
        $this->handshake($client);
        $before = count($client->stdoutLines());

        $result = $this->resultOf($client, 'tools/call', ['name' => 'progress', 'arguments' => ['msg' => 'x', 'session_id' => 'start'], '_meta' => ['progressToken' => 'p1']]);

        $sessionId = $this->issuedSessionId($result);
        $this->assertSame([$this->issued($sessionId), 'done: x'], $this->texts($result));
        $messages = array_map(Json::decode(...), array_slice($client->stdoutLines(), $before));
        $this->assertCount(2, $messages, 'the notification, then the answer');
        $this->assertSame('notifications/progress', JsonAssert::at($messages[0], 'method'));
        $this->assertSame('p1', JsonAssert::at($messages[0], 'params', 'progressToken'));
        $this->assertSame($this->nextId - 1, JsonAssert::at($messages[1], 'id'));

        $this->assertExitsAtEof($client);
        $event = $this->event();
        $this->assertSame('progress', JsonAssert::at($event, 'resource_name'));
        $this->assertSame('done: x', JsonAssert::at($event, 'response', 'content', 0, 'text'));
        $this->assertSame($sessionId, JsonAssert::at($event, 'session_id'));
    }

    /** The pump at handler entry delivers an earlier call's event while stdin stays open. */
    #[MaximumDuration(15000)]
    public function testAnEventIsDeliveredBetweenRequestsWhileStdinStaysOpen(): void
    {
        $client = $this->start();
        $this->handshake($client);
        $sessionId = $this->issuedSessionId($this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'call 1', 'session_id' => 'start']]));

        $calls = 1;
        $delivered = null;
        while (!$delivered instanceof \stdClass && $calls < self::MAX_CALLS_BEFORE_DELIVERY) {
            ++$calls;
            $answer = $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'call ' . $calls, 'session_id' => $sessionId]]);
            $this->assertSame(['echo: call ' . $calls], $this->texts($answer));
            $delivered = $this->eventWithMessage('call 1', self::BETWEEN_CALLS_SECONDS);
        }

        $this->assertInstanceOf(\stdClass::class, $delivered, sprintf('the first event reached the collector within %d calls', $calls));
        $this->assertTrue($client->process()->isRunning(), 'stdin is still open');
        $this->assertSame($sessionId, JsonAssert::at($delivered, 'session_id'));
        $this->assertExitsAtEof($client);
        $this->assertCount($calls, $this->events($calls), 'every call is delivered by the end');
    }

    /**
     * Review Focus 3: stdin closes while the collector holds every delivery. The pump at each handler entry starts at
     * most 5 deliveries, so 2 of the 7 events still wait when the 5 s shutdown budget ends.
     */
    #[Group('ac-03-9')]
    #[MaximumDuration(15000)]
    public function testStdinClosingWhileTheCollectorIsSlowEndsWithinTheShutdownBudget(): void
    {
        $client = $this->start(['status' => 202, 'delayMs' => 8000]);
        $this->handshake($client);
        $sessionId = $this->issuedSessionId($this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'call 1', 'session_id' => 'start']]));
        for ($call = 2; $call <= 7; ++$call) {
            $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'call ' . $call, 'session_id' => $sessionId]]);
        }

        $this->assertExitsAtEof($client, self::SLOW_COLLECTOR_EXIT_SECONDS);
        $log = $this->log();
        $this->assertSame(1, substr_count($log, 'Shutting down with '), $log);
        $this->assertMatchesRegularExpression('/\] Shutting down with 2 events still in queue$/m', $log);
        $this->assertStdoutIsOnlyJsonRpc($client);
        $this->assertSame('', $client->process()->getErrorOutput());
    }

    /** SIGTERM with stdin open: the drain delivers the event, then the default disposition ends the process. */
    #[Group('ac-03-10')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    #[MaximumDuration(15000)]
    public function testSigtermDeliversTheQueueAndThenEndsTheProcessBySignal(): void
    {
        $client = $this->start();
        $this->handshake($client);
        $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => 'start']]);
        $this->assertSame([], $this->collector()->requests($this->scenario), 'nothing pumped the event yet');

        $sentAt = microtime(true);
        $client->signal(self::SIGTERM);
        $code = $client->waitForExit(self::SIGTERM_EXIT_SECONDS);

        $this->assertNotNull($code, sprintf('the process ended within %.1f s', self::SIGTERM_EXIT_SECONDS));
        $this->assertTrue($client->process()->hasBeenSignaled(), sprintf('exit code %d after %.3f s', $code, microtime(true) - $sentAt));
        $this->assertSame(self::SIGTERM, $client->process()->getTermSignal());
        $event = $this->event();
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
        $this->assertStdoutIsOnlyJsonRpc($client);
    }

    /**
     * 06 AC-62 in a real process: stdin EOF posts the diagnostics buffer exactly once, with the setup beacons and the
     * per-event line logged during the EOF drain.
     */
    #[Group('ac-06-62')]
    #[MaximumDuration(15000)]
    public function testStdinEofSendsTheDiagnosticsOnce(): void
    {
        $diagnostics = $this->collector()->scenario();
        $startedAt = microtime(true);
        $client = $this->start(env: ['AGENTCAT_DISABLE_DIAGNOSTICS' => 'false', 'AGENTCAT_DIAGNOSTICS_ENDPOINT' => $diagnostics]);
        $this->handshake($client);
        $this->resultOf($client, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'one', 'session_id' => 'start']]);

        $this->assertExitsAtEof($client);

        // The server lived less than the 2 s window, so no handler-entry pump and no shutdown pass could post the
        // buffer early: the EOF flush is the only POST. Counted after the exit, so a late duplicate is seen too.
        $lifetime = microtime(true) - $startedAt;
        $this->assertLessThan(Diagnostics::BATCH_FLUSH_SECONDS, $lifetime, 'the server ended inside the 2 s window');
        $requests = $this->collector()->requests($diagnostics);
        $this->assertCount(1, $requests, sprintf('one diagnostics POST; the server lived %.3f s', $lifetime));
        $this->assertSame('/v1/logs', $requests[0]->path);
        $body = MockCollector::body($requests[0]);
        $this->assertStringContainsString('"stringValue":"php"', $body);
        $this->assertStringContainsString('AgentCat setup complete | project proj_', $body);
        $this->assertSame(1, substr_count($body, 'Successfully sent event '), 'the per-event line of the EOF drain');
    }

    /** mcp/sdk 0.9.0 and main serve the 2026-07-28 era over stdio when the first request carries its envelope. */
    #[MaximumDuration(15000)]
    public function testAModernEraFirstRequestIsTrackedWithoutAHandshake(): void
    {
        if (!McpSdkVersion::hasModernEra()) {
            self::markTestSkipped('mcp/sdk 0.8.1 serves only the handshake era over stdio');
        }
        $client = $this->start();

        $result = $this->resultOf($client, 'tools/call', HttpHarness::modernParams(
            ['name' => 'echo', 'arguments' => ['msg' => 'm']],
            ['name' => 'modern-client', 'version' => '2.0.0'],
        ));

        $sessionId = $this->issuedSessionId($result);
        $this->assertSame([$this->issued($sessionId), 'echo: m'], $this->texts($result));
        $this->assertExitsAtEof($client);
        $event = $this->event();
        $this->assertSame('modern-client', JsonAssert::at($event, 'client_name'));
        $this->assertSame(HttpHarness::MODERN_PROTOCOL_VERSION, JsonAssert::at($event, 'tags', Constants::TAG_PROTOCOL_VERSION));
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
    }

    /**
     * Starts this test's server with its own collector scenario and HOME. `AGENTCAT_API_URL` and
     * `AGENTCAT_DIAGNOSTICS_ENDPOINT` are closed local ports, so an event without the scenario can never reach the
     * AgentCat API, and diagnostics, which stay off by the inherited `AGENTCAT_DISABLE_DIAGNOSTICS=true`, can never reach
     * AgentCat's collector.
     *
     * @param array{status: int, delayMs?: int, body?: string} $default the collector's answer to every event
     * @param array<string, string>                            $env     more environment variables of the server, applied last
     */
    private function start(array $default = ['status' => 202, 'body' => '{"status":"accepted"}'], array $env = []): StdioMcpClient
    {
        $this->scenario = $this->collector()->scenario([], $default);
        $this->home = self::$dir . '/home-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->home, 0o700));
        $this->client = StdioMcpClient::start(__DIR__ . '/Fixtures/stdio-server.php', [
            'AGENTCAT_ENABLED' => 'true',
            'AGENTCAT_API_URL' => MockCollector::closedPortUrl(),
            'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(),
            'AGENTCAT_E2E_COLLECTOR' => $this->scenario,
            'HOME' => $this->home,
            ...$env,
        ]);

        return $this->client;
    }

    /** `initialize` and `notifications/initialized`, as an MCP host opens a stdio session. */
    private function handshake(StdioMcpClient $client): void
    {
        $result = $this->resultOf($client, 'initialize', [
            'protocolVersion' => self::HANDSHAKE_PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'stdio-client', 'version' => '1.0.0'],
        ]);
        $this->assertSame(self::HANDSHAKE_PROTOCOL_VERSION, JsonAssert::at($result, 'protocolVersion'));
        $client->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    /**
     * Sends one request and returns the `result` of its answer.
     *
     * @param array<string, mixed> $params
     */
    private function resultOf(StdioMcpClient $client, string $method, array $params): \stdClass
    {
        $id = $this->nextId++;
        $client->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]);

        return HttpHarness::result($client->receive($id));
    }

    /** Closes stdin and waits for exit code 0. */
    private function assertExitsAtEof(StdioMcpClient $client, float $timeoutSeconds = self::EOF_EXIT_SECONDS): void
    {
        $closedAt = microtime(true);
        $client->closeInput();
        $code = $client->waitForExit($timeoutSeconds);
        $this->assertSame(0, $code, sprintf('exit within %.1f s of stdin EOF (%.3f s); stderr: %s', $timeoutSeconds, microtime(true) - $closedAt, $client->process()->getErrorOutput()));
    }

    /** Every byte on stdout belongs to a JSON-RPC message, one per line. */
    private function assertStdoutIsOnlyJsonRpc(StdioMcpClient $client): void
    {
        $lines = $client->stdoutLines();
        $this->assertNotSame([], $lines);
        $this->assertSame(implode("\n", $lines) . "\n", $client->process()->getOutput(), 'stdout holds whole lines only');
        foreach ($lines as $line) {
            $this->assertJson($line);
            $message = Json::decode($line);
            $this->assertInstanceOf(\stdClass::class, $message, $line);
            $this->assertSame('2.0', $message->jsonrpc ?? null, $line);
        }
    }

    /**
     * Waits until the collector holds `$count` events of this test's scenario and returns their decoded bodies.
     *
     * @return list<\stdClass>
     */
    private function events(int $count): array
    {
        $deadline = microtime(true) + self::COLLECTOR_DEADLINE_SECONDS;
        do {
            $requests = $this->collector()->requests($this->scenario);
            if (count($requests) >= $count) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->assertCount($count, $requests, 'POSTs to the collector');

        return array_map(function (\stdClass $request): \stdClass {
            $this->assertSame('POST', $request->method);
            $body = $request->body;
            $this->assertIsString($body);
            $event = Json::decode($body);
            $this->assertInstanceOf(\stdClass::class, $event, $body);

            return $event;
        }, $requests);
    }

    /** Waits for the one event of this test's scenario and returns its decoded body. */
    private function event(): \stdClass
    {
        $event = $this->events(1)[0] ?? null;
        $this->assertInstanceOf(\stdClass::class, $event);

        return $event;
    }

    /** The collector's event whose call had `msg` `$msg`, waiting up to `$deadlineSeconds`; null if none came. */
    private function eventWithMessage(string $msg, float $deadlineSeconds): ?\stdClass
    {
        $deadline = microtime(true) + $deadlineSeconds;
        do {
            foreach ($this->collector()->requests($this->scenario) as $request) {
                $event = is_string($request->body ?? null) ? Json::decode($request->body) : null;
                if ($event instanceof \stdClass && JsonAssert::at($event, 'parameters', 'request', 'params', 'arguments', 'msg') === $msg) {
                    return $event;
                }
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /** The server's agentcat.log. */
    private function log(): string
    {
        $log = file_get_contents($this->home . '/agentcat.log');
        $this->assertIsString($log);

        return $log;
    }

    /** The `ses_` ID of the issued block at the start of an answer. */
    private function issuedSessionId(\stdClass $result): string
    {
        $text = JsonAssert::at($result, 'content', 0, 'text');
        $this->assertIsString($text);
        $this->assertSame(1, preg_match('/^session_id: (ses_[0-9A-Za-z]{27})$/m', $text, $match), $text);

        return $match[1];
    }

    private function issued(string $sessionId): string
    {
        return implode("\n", [Constants::MINT_BACK_HEADER_ISSUED, 'session_id: ' . $sessionId, Constants::MINT_BACK_ISSUED_BODY]);
    }

    /** @return list<mixed> the `text` of every content block */
    private function texts(\stdClass $result): array
    {
        $content = $result->content ?? null;
        $this->assertIsList($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), $content);
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
