<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\E2E\Support\BuiltInServer;
use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\E2E\Support\StdioMcpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\SleepingIdentify;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * A Laravel application whose only AgentCat code is the auto-discovered service provider (`Laravel/bootstrap.php`),
 * with laravel/mcp's `conformance-server`: served over HTTP by `php -S` through Laravel's own front-controller steps
 * (`Laravel/http.php`), and over stdio by `php artisan mcp:start` (`Laravel/artisan.php`). Under `php -S` every request
 * is a fresh PHP request with a fresh application, like PHP-FPM, and AgentCat's shutdown function delivers its event
 * within the 5 s budget. A stdio server is one process for the whole MCP session, like a
 * server that an MCP host starts; it delivers at stdin EOF and at SIGTERM.
 */
final class LaravelEndToEndTest extends TestCase
{
    private const string PROJECT_ID = 'proj_eeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const string HANDSHAKE_PROTOCOL_VERSION = '2025-06-18';

    /** How long a test waits for its event at the collector. */
    private const float COLLECTOR_DEADLINE_SECONDS = 5.0;

    /** The hook sleeps first, then the delivery has its usual time. */
    private const float SLEEPING_HOOK_DEADLINE_SECONDS = SleepingIdentify::SECONDS + self::COLLECTOR_DEADLINE_SECONDS;

    /** The 5 s shutdown budget (D47), plus room for a slow runner: a stdio server ends within it at EOF and SIGTERM. */
    private const float EXIT_SECONDS = 6.0;

    private const int SIGTERM = 15;

    private static ?MockCollector $collector = null;

    private static ?BuiltInServer $http = null;

    /** The directory of this class under the test HOME: one HOME per server, each with its application. */
    private static string $dir = '';

    /** The HOME of the `php -S` server: its application, with AgentCat's log, and the hook's marker. */
    private static string $httpHome = '';

    /** @var list<StdioMcpClient> the stdio servers of the current test */
    private array $servers = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $home = getenv('HOME');
        self::assertIsString($home);
        self::$dir = $home . '/e2e-laravel-' . bin2hex(random_bytes(6));

        try {
            self::assertTrue(mkdir(self::$dir, 0o700));
            self::$collector = MockCollector::start();
            self::$httpHome = self::home();
            self::$http = BuiltInServer::start(__DIR__ . '/Laravel/http.php', self::environment(self::$httpHome));
        } catch (\Throwable $e) {
            self::tearDownAfterClass(); // PHPUnit skips it when this method fails

            throw $e;
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$http?->stop();
        self::$http = null;
        self::$collector?->stop();
        self::$collector = null;
        FileTree::remove(self::$dir);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->process()->stop(0);
        }
        $this->servers = [];
    }

    #[Group('ac-02-43')]
    #[Group('ac-03-12')]
    #[MaximumDuration(15000)]
    public function testAnHttpCallIsDeliveredWithItsHeadersByTheEndOfTheRequest(): void
    {
        $scenario = $this->collector()->scenario();
        FileTree::remove($this->agentCatLogPath(self::$httpHome)); // the HTTP tests share it: only this request may write the line

        $answer = $this->callOverHttp(['X-E2E-Collector' => $scenario, 'X-AgentCat-Health-Run' => 'L']);

        $this->assertSame(200, $answer['status']);
        $this->assertSame('echo: hi', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text'));
        $body = $this->event($scenario); // the shutdown function delivers within the 5 s budget (per-request SAPI)
        $this->assertSame(['conformance-server', 'e2e-client', 'L'], [JsonAssert::at($body, 'server_name'), JsonAssert::at($body, 'client_name'), JsonAssert::at($body, 'parameters', 'extra', 'requestInfo', 'headers', 'x-agentcat-health-run')]);
        $this->assertStringContainsString('AgentCat setup started | project ' . self::PROJECT_ID . ' | sdk laravel-mcp/server', $this->agentCatLog(self::$httpHome)); // D60
        $this->assertStringNotContainsString('AgentCat', $this->laravelLog(self::$httpHome));
    }

    /**
     * D59 with D57 under `php -S`: AgentCat's listener gives laravel/mcp's plain answer its length, Laravel sends it, and
     * only then does AgentCat's shutdown function run the hooks and deliver, so the client has the whole answer first.
     * The hook sleeps 3 s; the 2 s bound leaves room for a slow runner, and the marker and the collector prove the order.
     */
    #[Group('ac-02-48')]
    #[Group('ac-03-1')]
    #[MaximumDuration(15000)]
    public function testAnAnswerGetsItsLengthAndReachesTheClientBeforeTheHooksAndTheDelivery(): void
    {
        $scenario = $this->collector()->scenario();
        $this->removeMarker();
        $sentAt = microtime(true);

        $answer = $this->callOverHttp(['X-E2E-Collector' => $scenario, 'X-E2E-Identify-Sleep' => '1']);

        $this->assertLessThan(SleepingIdentify::SECONDS - 1.0, $answer['receivedAt'] - $sentAt, 'the answer arrived before the hook woke up');
        $this->assertSame((string) strlen($answer['body']), $answer['headers']['content-length'] ?? null);
        $this->assertSame('echo: hi', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text'));
        $event = $this->event($scenario, self::SLEEPING_HOOK_DEADLINE_SECONDS);
        $this->assertSame('e2e-user', JsonAssert::at($event, 'identify_actor_given_id'));
        $this->assertGreaterThan($answer['receivedAt'], $this->hookWokeAt(), 'identify ran after the client had its answer');
        $this->assertGreaterThan($answer['receivedAt'], $this->deliveredAt($scenario), 'the delivery started after the client had its answer');
    }

    /**
     * D59 leaves a streamed answer as laravel/mcp sends it: server-sent events without `Content-Length`, the progress
     * notifications, then the result with AgentCat's session block. Its event comes when the stream ends.
     */
    #[MaximumDuration(15000)]
    public function testAStreamedAnswerKeepsItsOwnHeaders(): void
    {
        $scenario = $this->collector()->scenario();

        $answer = $this->callOverHttp(['X-E2E-Collector' => $scenario], 'stream', '/mcp/e2e-stream');

        $this->assertSame(200, $answer['status']);
        $this->assertStringStartsWith('text/event-stream', $answer['headers']['content-type'] ?? '');
        $this->assertArrayNotHasKey('content-length', $answer['headers']);
        $this->assertSame(3, substr_count($answer['body'], 'data: '), 'two notifications, then the result');
        $this->assertSame('done', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text'));
        $this->assertSame('stream', JsonAssert::at($this->event($scenario), 'resource_name'));
    }

    /**
     * Stray output before the answer under `php -S` with php.ini-production's `output_buffering=4096`, as on mod_php
     * and php-cgi: the newline waits in PHP's output buffer and goes out before the JSON, so a length of the JSON alone
     * would cut its end. AgentCat then leaves the answer as laravel/mcp sends it, and the client reads all of it when the
     * request ends. Without stray output, the same buffering still gives the answer its length.
     */
    #[MaximumDuration(15000)]
    public function testStrayOutputBeforeTheAnswerKeepsTheAnswerWhole(): void
    {
        $scenario = $this->collector()->scenario();
        $server = BuiltInServer::start(__DIR__ . '/Laravel/http.php', self::environment(self::home()), ['output_buffering' => '4096']);

        try {
            $sized = $this->callOverHttp(['X-E2E-Collector' => $this->collector()->scenario()], server: $server);
            $answer = $this->callOverHttp(['X-E2E-Collector' => $scenario, 'X-E2E-Stray-Output' => '1'], server: $server);
        } finally {
            $server->stop();
        }

        $this->assertSame((string) strlen($sized['body']), $sized['headers']['content-length'] ?? null, 'an answer without stray output gets its length');
        $this->assertSame(200, $answer['status']);
        $this->assertStringStartsWith("\n{", $answer['body']);
        $this->assertSame('echo: hi', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text')); // the whole JSON decodes
        $this->assertArrayNotHasKey('content-length', $answer['headers']);
        $this->assertSame('echo', JsonAssert::at($this->event($scenario), 'resource_name'));
    }

    #[Group('ac-03-37')]
    #[Group('ac-02-44')]
    #[Group('ac-03-12')]
    #[MaximumDuration(20000)]
    public function testAStdioServerDeliversAtEofAndWritesOnlyJsonRpc(): void
    {
        $scenario = $this->collector()->scenario();
        $server = $this->startStdio(['AGENTCAT_API_URL' => $scenario]);
        $this->call($server);
        $this->assertSame([], $this->collector()->requests($scenario), 'nothing delivered the event before EOF');

        $closedAt = microtime(true);
        $server->closeInput();

        $this->assertSame(0, $server->waitForExit(self::EXIT_SECONDS), sprintf('exit within %.1f s of stdin EOF (%.3f s); stderr: %s', self::EXIT_SECONDS, microtime(true) - $closedAt, $server->process()->getErrorOutput()));
        $this->assertStdoutIsOnlyJsonRpc($server);
        $this->assertSame('', $server->process()->getErrorOutput());
        $event = $this->event($scenario);
        $this->assertSame('desktop', JsonAssert::at($event, 'client_name'));
        $this->assertSame('conformance-server', JsonAssert::at($event, 'server_name'));
    }

    /** SIGTERM with stdin open: the drain delivers the event, then the default disposition ends the process. */
    #[Group('ac-03-10')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    #[MaximumDuration(20000)]
    public function testSigtermDeliversTheQueuedEventAndThenEndsTheProcessBySignal(): void
    {
        $scenario = $this->collector()->scenario();
        $server = $this->startStdio(['AGENTCAT_API_URL' => $scenario]);
        $this->call($server);
        $this->assertSame([], $this->collector()->requests($scenario), 'nothing delivered the event before the signal');

        $sentAt = microtime(true);
        $server->signal(self::SIGTERM);
        $code = $server->waitForExit(self::EXIT_SECONDS);

        $this->assertNotNull($code, sprintf('the process ended within %.1f s', self::EXIT_SECONDS));
        $this->assertTrue($server->process()->hasBeenSignaled(), sprintf('exit code %d after %.3f s', $code, microtime(true) - $sentAt));
        $this->assertSame(self::SIGTERM, $server->process()->getTermSignal());
        $this->assertSame('echo', JsonAssert::at($this->event($scenario), 'resource_name'));
        $this->assertStdoutIsOnlyJsonRpc($server);
    }

    /**
     * D53, Laravel Boost's pattern: a local server with its own `tools/call` handler, excluded in config/agentcat.php.
     * Its whole stdio session is byte-identical to the same application without AgentCat's provider, and nothing is
     * logged or sent.
     */
    #[MaximumDuration(20000)]
    public function testAnExcludedServerAnswersExactlyAsWithoutAgentCat(): void
    {
        $scenario = $this->collector()->scenario();

        $excluded = $this->session(['AGENTCAT_API_URL' => $scenario, 'AGENTCAT_E2E_HANDLE' => 'boost-style']);
        $withoutAgentCat = $this->session(['AGENTCAT_E2E_HANDLE' => 'boost-style', 'AGENTCAT_E2E_NO_PROVIDER' => '1']);

        $this->assertSame($withoutAgentCat['stdout'], $excluded['stdout']);
        $lines = explode("\n", rtrim($excluded['stdout'], "\n"));
        $this->assertCount(3, $lines, 'the answers to initialize, tools/list and tools/call');
        $tools = JsonAssert::at(Json::decode($lines[1]), 'result', 'tools');
        $this->assertIsList($tools);
        $this->assertSame(['echo'], array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $tools));
        $this->assertSame('[{"type":"text","text":"echo: hi"}]', json_encode(JsonAssert::at(Json::decode($lines[2]), 'result', 'content'), JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->collector()->requests($scenario));
        $this->assertStringNotContainsString('AgentCat', $this->laravelLog($excluded['home']));
        $this->assertFileDoesNotExist($this->agentCatLogPath($excluded['home']));
        $this->assertFileDoesNotExist($excluded['home'] . '/agentcat.log');
    }

    /**
     * The environment of every server: tracking on, the project, and an application in `$home/app`. The AgentCat API
     * and diagnostics are closed local ports, so an event without the test's collector (`X-E2E-Collector` over HTTP,
     * `AGENTCAT_API_URL` for a stdio server) never reaches the AgentCat API.
     *
     * @return array<string, string>
     */
    private static function environment(string $home): array
    {
        return [
            'AGENTCAT_ENABLED' => 'true',
            'AGENTCAT_PROJECT_ID' => self::PROJECT_ID,
            'AGENTCAT_API_URL' => MockCollector::closedPortUrl(),
            'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(),
            'HOME' => $home,
            'APP_BASE_PATH' => $home . '/app',
        ];
    }

    /** A new HOME under this class's directory, with the directories its Laravel application writes to. */
    private static function home(): string
    {
        $home = self::$dir . '/home-' . bin2hex(random_bytes(6));
        foreach (['bootstrap/cache', 'storage/framework/views', 'storage/logs'] as $directory) {
            self::assertTrue(mkdir($home . '/app/' . $directory, 0o700, true));
        }

        return $home;
    }

    /**
     * A `tools/call` over HTTP in the 2026-07-28 era: no handshake, the client in `_meta`. `echo` gets `msg` `hi`. The
     * class's `php -S` server answers unless the test passes its own.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string, receivedAt: float}
     */
    private function callOverHttp(array $headers, string $tool = 'echo', string $path = '/mcp/e2e', ?BuiltInServer $server = null): array
    {
        $server ??= self::$http;
        $this->assertInstanceOf(BuiltInServer::class, $server);
        $client = new McpHttpClient($server->baseUrl() . $path);
        $arguments = $tool === 'echo' ? ['msg' => 'hi', 'session_id' => 'start', 'context' => 'e2e'] : ['session_id' => 'start'];
        $params = HttpHarness::modernParams(['name' => $tool, 'arguments' => (object) $arguments], ['name' => 'e2e-client', 'version' => '1.0.0']);

        return $client->post(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params], [...HttpHarness::modernHeaders('tools/call', $tool), ...$headers]);
    }

    /** @param array{status: int, headers: array<string, string>, body: string, receivedAt: float} $answer */
    private function answerResult(array $answer): \stdClass
    {
        return HttpHarness::result(HttpHarness::decode($answer['body'], $answer['headers']['content-type'] ?? '', 1));
    }

    /**
     * Starts `php artisan mcp:start` with a new HOME and its application.
     *
     * @param array<string, string> $env more environment variables of the server
     */
    private function startStdio(array $env, ?string $home = null): StdioMcpClient
    {
        $server = StdioMcpClient::start(__DIR__ . '/Laravel/artisan.php', [...self::environment($home ?? self::home()), ...$env]);
        $this->servers[] = $server;

        return $server;
    }

    /** `initialize` from the `desktop` client, as an MCP host opens a stdio session. */
    private function initialize(StdioMcpClient $server): void
    {
        $server->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => self::HANDSHAKE_PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'desktop', 'version' => '0.9']]]);
        $server->receive(1);
    }

    /** `initialize`, then `tools/call` `echo`, as the stdio tests send them. */
    private function call(StdioMcpClient $server): void
    {
        $this->initialize($server);
        $server->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => 'start']]]);
        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($server->receive(2)), 'content', 1, 'text'));
    }

    /**
     * A whole stdio session with a new HOME — the handshake, `tools/list`, `tools/call` `echo`, stdin EOF — and what
     * the server wrote to stdout.
     *
     * @param array<string, string> $env
     *
     * @return array{stdout: string, home: string}
     */
    private function session(array $env): array
    {
        $home = self::home();
        $server = $this->startStdio($env, $home);
        $this->initialize($server);
        $server->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $server->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()]);
        $server->receive(2);
        $server->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi']]]);
        $server->receive(3);
        $server->closeInput();
        $this->assertSame(0, $server->waitForExit(self::EXIT_SECONDS), $server->process()->getErrorOutput());
        $this->assertSame('', $server->process()->getErrorOutput());

        return ['stdout' => $server->process()->getOutput(), 'home' => $home];
    }

    /** Every byte on stdout belongs to a JSON-RPC message, one per line. */
    private function assertStdoutIsOnlyJsonRpc(StdioMcpClient $server): void
    {
        $lines = $server->stdoutLines();
        $this->assertNotSame([], $lines);
        $this->assertSame(implode("\n", $lines) . "\n", $server->process()->getOutput(), 'stdout holds whole lines only');
        foreach ($lines as $line) {
            $message = Json::decode($line);
            $this->assertInstanceOf(\stdClass::class, $message, 'stdout carries only JSON-RPC: ' . $line);
            $this->assertSame('2.0', $message->jsonrpc ?? null, $line);
        }
    }

    /** Waits for the one event of the scenario and returns its decoded body. */
    private function event(string $scenario, float $deadlineSeconds = self::COLLECTOR_DEADLINE_SECONDS): \stdClass
    {
        $deadline = microtime(true) + $deadlineSeconds;
        do {
            $requests = $this->collector()->requests($scenario);
            if ($requests !== []) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->assertCount(1, $requests, 'one POST to the collector');
        $this->assertSame('POST', $requests[0]->method);
        $body = $requests[0]->body;
        $this->assertIsString($body);
        $event = Json::decode($body);
        $this->assertInstanceOf(\stdClass::class, $event, $body);

        return $event;
    }

    /** When the collector got the scenario's one POST. */
    private function deliveredAt(string $scenario): float
    {
        $requests = $this->collector()->requests($scenario);
        $this->assertCount(1, $requests);
        $start = $requests[0]->start;
        $this->assertIsFloat($start);

        return $start;
    }

    /** When `SleepingIdentify` woke up in the `php -S` server. */
    private function hookWokeAt(): float
    {
        $marker = file_get_contents(self::$httpHome . '/' . SleepingIdentify::MARKER);
        $this->assertIsString($marker);
        $this->assertIsNumeric($marker);

        return (float) $marker;
    }

    private function removeMarker(): void
    {
        FileTree::remove(self::$httpHome . '/' . SleepingIdentify::MARKER);
    }

    /** Where AgentCat's lines go in the application of `$home`: its own file (D60). */
    private function agentCatLogPath(string $home): string
    {
        return $home . '/app/storage/logs/agentcat.log';
    }

    /** What AgentCat wrote to its own file in the application of `$home`, '' before its first line. */
    private function agentCatLog(string $home): string
    {
        $path = $this->agentCatLogPath($home);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** What the application of `$home` wrote to its own log (Laravel's default channel), '' before its first line. */
    private function laravelLog(string $home): string
    {
        $path = $home . '/app/storage/logs/laravel.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
