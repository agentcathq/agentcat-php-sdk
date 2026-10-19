<?php

declare(strict_types=1);

namespace AgentCat\Tests\E2E;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Tests\E2E\Support\BuiltInServer;
use AgentCat\Tests\E2E\Support\McpHttpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A real mcp/sdk server under `php -S`, tracked with one `AgentCat::track()` line (`Fixtures/http-server.php`), driven
 * over HTTP with raw JSON-RPC. Every request is a fresh PHP request with its own Builder, like PHP-FPM; the handshake
 * lives in a `FileSessionStore`. AgentCat delivers each event at the end of its request to the mock collector.
 */
final class HttpEndToEndTest extends TestCase
{
    private const float COLLECTOR_DEADLINE_SECONDS = 5.0;

    /** How long the diagnostics test waits for the two requests' diagnostics after the second answer. */
    private const float DIAGNOSTICS_DEADLINE_SECONDS = 8.0;

    /**
     * The diagnostics test's collector answers each event after this long, so the per-event line, and the POST that
     * carries it, come at least that long after the response: its order check keeps 1 s of slack on a loaded runner.
     */
    private const int SLOW_EVENT_MS = 1000;

    /** The fixture's `identify` sleeps 3 s with `X-E2E-Identify-Sleep: 1`, before the delivery. */
    private const float SLEEPING_HOOK_DEADLINE_SECONDS = 8.0;

    private const float LOG_DEADLINE_SECONDS = 5.0;

    /** The SDK's line for a failed delivery attempt; curl's error names the host and the port. */
    private const string FAILED_DELIVERY = '/^.*Failed to send event evt_\w+, retrying\.\.\. \[Error: .*$/m';

    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private static ?MockCollector $collector = null;

    private static ?BuiltInServer $server = null;

    /** HOME (agentcat.log), sessions and markers of the server, under the test HOME (Ruling R8). */
    private static string $dir = '';

    /** `AGENTCAT_API_URL` of the server: a closed port on 127.0.0.1, so a request without a collector stays local. */
    private static string $fallbackUrl = '';

    /** The collector scenario of the current test. */
    private string $scenario = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        $home = getenv('HOME');
        self::assertIsString($home);
        self::$dir = $home . '/e2e-http-' . bin2hex(random_bytes(6));

        try {
            foreach (['', '/home', '/sessions', '/markers'] as $sub) {
                self::assertTrue(mkdir(self::$dir . $sub, 0o700));
            }
            self::$collector = MockCollector::start();
            self::$fallbackUrl = MockCollector::closedPortUrl();
            self::$server = BuiltInServer::start(__DIR__ . '/Fixtures/http-server.php', [
                'AGENTCAT_ENABLED' => 'true',
                'AGENTCAT_API_URL' => self::$fallbackUrl,
                'AGENTCAT_DIAGNOSTICS_ENDPOINT' => MockCollector::closedPortUrl(), // a request without X-E2E-Diagnostics stays local
                'HOME' => self::$dir . '/home',
                'AGENTCAT_E2E_SESSIONS' => self::$dir . '/sessions',
                'AGENTCAT_E2E_MARKERS' => self::$dir . '/markers',
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

    #[\Override]
    protected function setUp(): void
    {
        $this->scenario = $this->collector()->scenario();
    }

    /** M6: one tracked call through the handshake, the session store and the shutdown delivery. */
    #[Group('ac-02-53')]
    #[Group('ac-02-43')]
    #[Group('ac-04-49')]
    #[MaximumDuration(10000)]
    public function testAHandshakeCallIsTrackedEndToEnd(): void
    {
        $client = $this->client();
        $client->initialize();
        $tools = $client->listTools()->tools ?? null;
        $this->assertIsList($tools);
        $names = array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $tools);
        $this->assertSame(['echo', 'strict', 'boom', GetMoreTools::NAME], $names);
        $this->assertSame(['msg', Constants::PARAM_SESSION_ID, Constants::PARAM_CONTEXT], $this->keys(JsonAssert::at($tools, 0, 'inputSchema', 'properties')));

        $answer = $client->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => 'start'], $this->headers(['X-AgentCat-Health-Run' => 'S']));

        $event = $this->event();
        $sessionId = JsonAssert::at($event, 'session_id');
        $this->assertIsString($sessionId);
        $this->assertMatchesRegularExpression('/\Ases_[0-9A-Za-z]{27}\z/', $sessionId);
        $this->assertSame([$this->issued($sessionId), 'echo: hi'], $this->texts(HttpHarness::result($answer['message'])));
        $this->assertSame('echo', JsonAssert::at($event, 'resource_name'));
        $this->assertSame('why', JsonAssert::at($event, 'user_intent'));
        $this->assertSame('e2e-client', JsonAssert::at($event, 'client_name'), 'the handshake ladder through FileSessionStore');
        $this->assertSame('e2e-http-server', JsonAssert::at($event, 'server_name'));
        $this->assertSame('3.1.4', JsonAssert::at($event, 'server_version'));
        $this->assertSame('e2e-user', JsonAssert::at($event, 'identify_actor_given_id'));
        $this->assertSame('{"plan":"pro"}', $this->json(JsonAssert::at($event, 'identify_data')));
        $this->assertSame('minted', JsonAssert::at($event, 'tags', Constants::TAG_SESSION_SOURCE));
        $this->assertSame(
            '{"msg":"hi","context":"why","session_id":"start"}',
            $this->json(JsonAssert::at($event, 'parameters', 'request', 'params', 'arguments')),
        );
        $extra = JsonAssert::at($event, 'parameters', 'extra');
        $this->assertSame($client->sessionId(), JsonAssert::at($extra, 'sessionId'));
        // ac-02-43, ac-04-49: the HTTP request, as TypeScript v1 records it (D34)
        $requestInfo = JsonAssert::at($extra, 'requestInfo');
        $this->assertInstanceOf(\stdClass::class, $requestInfo);
        $this->assertSame('S', JsonAssert::at($requestInfo, 'headers', 'x-agentcat-health-run'));
        $this->assertSame($this->server()->baseUrl() . '/', JsonAssert::at($requestInfo, 'url'));
        $this->assertObjectNotHasProperty('method', $requestInfo);
    }

    #[Group('ac-02-20')]
    #[MaximumDuration(10000)]
    public function testEveryRequestRebuildsTheInjectionRegistries(): void
    {
        $client = $this->client();
        $client->initialize();
        $client->listTools();
        $before = $this->rebuildLines();

        $client->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'], $this->headers());

        $this->event();
        $this->assertSame($before + 1, $this->rebuildLines(), 'the tools/list of the earlier request ran in another PHP request');
    }

    #[Group('ac-02-17')]
    #[MaximumDuration(10000)]
    public function testAStrictToolAcceptsTheInjectedParameters(): void
    {
        $client = $this->client();
        $client->initialize();

        $answer = $client->callTool('strict', ['msg' => 'hi', 'session_id' => 'start', 'context' => 'why'], $this->headers());

        $event = $this->event();
        $sessionId = JsonAssert::at($event, 'session_id');
        $this->assertIsString($sessionId);
        $this->assertSame([$this->issued($sessionId), 'strict: hi'], $this->texts(HttpHarness::result($answer['message'])));
        $this->assertObjectNotHasProperty('is_error', $event);
    }

    /**
     * Header conflict 6: the tool result does not wait for the hooks. The fixture's `identify` sleeps 3 s and then
     * writes the marker; it runs in the shutdown delivery, after the response. The 2 s bound leaves room for a slow
     * runner; the marker proves the order.
     */
    #[Group('ac-01-37')]
    #[Group('ac-02-48')]
    #[Group('ac-03-14')]
    #[MaximumDuration(15000)]
    public function testTheResponseDoesNotWaitForTheHooks(): void
    {
        $client = $this->client();
        $client->initialize();
        $sentAt = microtime(true);

        $answer = $client->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'], $this->headers(['X-E2E-Identify-Sleep' => '1']));

        $this->assertLessThan(2.0, $answer['receivedAt'] - $sentAt, 'the response arrived before the hook ran');
        HttpHarness::result($answer['message']);
        $event = $this->event(self::SLEEPING_HOOK_DEADLINE_SECONDS);
        $this->assertSame('e2e-user', JsonAssert::at($event, 'identify_actor_given_id'));
        $marker = file_get_contents(self::$dir . '/markers/identify');
        $this->assertIsString($marker);
        $this->assertIsNumeric($marker);
        $this->assertGreaterThan($answer['receivedAt'], (float) $marker, 'identify ran after the client had its response');
    }

    #[MaximumDuration(10000)]
    public function testAModernEraCallIsTrackedWithoutAHandshake(): void
    {
        $client = $this->client();

        $answer = $client->callToolModern('echo', ['msg' => 'm'], ['name' => 'modern-client', 'version' => '2.0.0'], $this->headers());

        $event = $this->event();
        $sessionId = JsonAssert::at($event, 'session_id');
        $this->assertIsString($sessionId);
        $this->assertSame([$this->issued($sessionId), 'echo: m'], $this->texts(HttpHarness::result($answer['message'])));
        $this->assertSame('modern-client', JsonAssert::at($event, 'client_name'));
        $this->assertSame(HttpHarness::MODERN_PROTOCOL_VERSION, JsonAssert::at($event, 'tags', Constants::TAG_PROTOCOL_VERSION));
        $extra = JsonAssert::at($event, 'parameters', 'extra');
        $this->assertInstanceOf(\stdClass::class, $extra);
        $this->assertObjectNotHasProperty('sessionId', $extra);
    }

    #[Group('ac-05-E5')]
    #[Group('ac-02-25')]
    #[MaximumDuration(10000)]
    public function testAnUncaughtToolExceptionGivesTheLibrarysErrorAndAnEventWithTheException(): void
    {
        $client = $this->client();
        $client->initialize();

        $answer = $client->callTool('boom', ['session_id' => 'start'], $this->headers());

        $this->assertSame('{"code":-32603,"message":"Error while executing tool"}', $this->json(JsonAssert::at($answer['message'], 'error')));
        $event = $this->event();
        $this->assertTrue(JsonAssert::at($event, 'is_error'));
        $this->assertSame('RuntimeException', JsonAssert::at($event, 'error', 'type'));
        $this->assertSame('e2e boom', JsonAssert::at($event, 'error', 'message'));
        $frames = JsonAssert::at($event, 'error', 'frames');
        $this->assertIsList($frames);
        $files = array_map(static fn(mixed $frame): mixed => JsonAssert::at($frame, 'filename'), $frames);
        $this->assertNotSame([], array_filter($files, static fn(mixed $file): bool => is_string($file) && str_ends_with($file, 'http-server.php')), $this->json($files));
    }

    #[Group('ac-02-40')]
    #[MaximumDuration(10000)]
    public function testGetMoreToolsIsAnsweredByAgentCat(): void
    {
        $client = $this->client();
        $client->initialize();

        $answer = $client->callTool(GetMoreTools::NAME, ['context' => 'need X'], $this->headers());

        $event = $this->event();
        $sessionId = JsonAssert::at($event, 'session_id');
        $this->assertIsString($sessionId);
        $this->assertSame([$this->issued($sessionId), $this->cannedText()], $this->texts(HttpHarness::result($answer['message'])));
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at($event, 'resource_name'));
    }

    /**
     * Review Focus 5. Repeated Accept lines differ by PHP version (Ruling R7): `php -S` on PHP 8.3 keeps the first
     * line, on PHP 8.4 and 8.5 it joins them with `, `. The fixture records the value the SAPI gave PHP, and the
     * event must carry exactly that.
     */
    #[MaximumDuration(10000)]
    public function testOddHeadersReachTheEventAsStrings(): void
    {
        $client = $this->client();
        $client->initialize();
        $cookie = 'big=' . str_repeat('c', 8_996);

        $answer = $client->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'], $this->headers([
            'X-Odd' => "caf\xE9",
            'Accept' => ['application/json', 'text/event-stream'],
            'Cookie' => $cookie,
            'X-E2E-Record-Accept' => '1',
        ]));

        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($answer['message']), 'content', 1, 'text'));
        $headers = JsonAssert::at($this->event(), 'parameters', 'extra', 'requestInfo', 'headers');
        $this->assertSame("caf\u{FFFD}", JsonAssert::at($headers, 'x-odd'));
        $this->assertSame(9_000, strlen($cookie));
        $this->assertSame($cookie, JsonAssert::at($headers, 'cookie'));
        $accept = file_get_contents(self::$dir . '/markers/accept');
        $this->assertIsString($accept);
        $this->assertStringStartsWith('application/json', $accept);
        $this->assertSame($accept, JsonAssert::at($headers, 'accept'));
    }

    /**
     * D47 (open question Q3): under `php -S`, like PHP-FPM, each request posts its own diagnostics once, at its end,
     * after its response. Each POST is tied to its request by the session in its `Successfully sent event` line: each
     * call mints its own session.
     */
    #[Group('ac-06-62')]
    #[MaximumDuration(15000)]
    public function testEachRequestSendsItsOwnDiagnosticsAfterTheResponse(): void
    {
        $this->scenario = $this->collector()->scenario([], ['status' => 202, 'delayMs' => self::SLOW_EVENT_MS, 'body' => '{"status":"accepted"}']);
        $diagnostics = $this->collector()->scenario();
        $client = $this->client();
        $client->initialize();

        $answeredAt = []; // when the client had each answer, by the session that the call minted
        foreach (['one', 'two'] as $msg) {
            $answer = $client->callTool('echo', ['msg' => $msg, 'session_id' => 'start'], $this->headers(['X-E2E-Diagnostics' => $diagnostics]));
            $result = HttpHarness::result($answer['message']);
            $this->assertSame('echo: ' . $msg, JsonAssert::at($result, 'content', 1, 'text'));
            $answeredAt[$this->issuedSessionId($result)] = $answer['receivedAt'];
        }

        $this->assertCount(2, $answeredAt, 'each call minted its own session');
        $sent = $this->diagnosticsWithASentEvent($diagnostics, 2);
        $this->assertCount(2, $sent, 'one diagnostics POST with the per-event line per tracked request');
        $this->assertCount(2, $this->collector()->requests($diagnostics), 'and no other: the 2 s window is for long-running processes only');
        foreach ($answeredAt as $sessionId => $receivedAt) {
            $own = array_values(array_filter($sent, fn(\stdClass $request): bool => str_contains($this->body($request), ' | session ' . $sessionId . ' | ')));
            $this->assertCount(1, $own, 'one diagnostics POST carries the event line of ' . $sessionId);
            $this->assertSame('/v1/logs', $own[0]->path);
            $this->assertSame(1, substr_count($this->body($own[0]), 'Successfully sent event '), 'one line each');
            $start = $own[0]->start;
            $this->assertIsFloat($start);
            $this->assertGreaterThan($receivedAt, $start, 'the diagnostics POST of ' . $sessionId . ' ran after its response');
        }
    }

    /** A call without `X-E2E-Collector` falls back to `AGENTCAT_API_URL`, a closed local port: never the default API. */
    #[MaximumDuration(10000)]
    public function testWithoutACollectorHeaderTheEventGoesToTheLocalFallback(): void
    {
        $client = $this->client();
        $client->initialize();
        $offset = strlen($this->log());

        HttpHarness::result($client->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'])['message']);

        $port = parse_url(self::$fallbackUrl, PHP_URL_PORT);
        $this->assertIsInt($port);
        $failure = $this->failedDelivery($offset);
        $this->assertStringContainsString('127.0.0.1 port ' . $port, $failure, 'the delivery went to AGENTCAT_API_URL');
        $this->assertStringNotContainsString('agentcat.com', substr($this->log(), $offset));
    }

    private function client(): McpHttpClient
    {
        return new McpHttpClient($this->server()->baseUrl() . '/');
    }

    /**
     * The headers of a tool call: this test's collector scenario, plus `$extra`.
     *
     * @param array<string, string|list<string>> $extra
     *
     * @return array<string, string|list<string>>
     */
    private function headers(array $extra = []): array
    {
        return ['X-E2E-Collector' => $this->scenario, ...$extra];
    }

    /** Waits for the one event of this test's scenario and returns its decoded body. */
    private function event(float $deadlineSeconds = self::COLLECTOR_DEADLINE_SECONDS): \stdClass
    {
        $deadline = microtime(true) + $deadlineSeconds;
        do {
            $requests = $this->collector()->requests($this->scenario);
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

    /**
     * Waits until `$count` diagnostics requests of the scenario carry a `Successfully sent event` line, or the deadline
     * passes, and returns those requests.
     *
     * @return list<\stdClass>
     */
    private function diagnosticsWithASentEvent(string $scenario, int $count): array
    {
        $deadline = microtime(true) + self::DIAGNOSTICS_DEADLINE_SECONDS;
        do {
            $sent = array_values(array_filter(
                $this->collector()->requests($scenario),
                fn(\stdClass $request): bool => str_contains($this->body($request), 'Successfully sent event '),
            ));
            if (count($sent) >= $count) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        return $sent;
    }

    private function body(\stdClass $request): string
    {
        $body = $request->body ?? null;
        $this->assertIsString($body);

        return $body;
    }

    /** How often the server's agentcat.log says that a request rebuilt the registries. */
    private function rebuildLines(): int
    {
        return substr_count($this->log(), self::REBUILT);
    }

    /** Waits for the first failed delivery that the server's agentcat.log reports after byte `$offset`. */
    private function failedDelivery(int $offset): string
    {
        $deadline = microtime(true) + self::LOG_DEADLINE_SECONDS;
        do {
            $written = substr($this->log(), $offset);
            if (preg_match(self::FAILED_DELIVERY, $written, $match) === 1) {
                return $match[0];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        self::fail('No failed delivery in agentcat.log: ' . $written);
    }

    /** The server's agentcat.log so far. */
    private function log(): string
    {
        $log = file_get_contents(self::$dir . '/home/agentcat.log');
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

    private function cannedText(): string
    {
        $text = JsonAssert::at(Golden::json('handles/strings.json'), 'GET_MORE_TOOLS_RESULT', 'content', 0, 'text');
        $this->assertIsString($text);

        return $text;
    }

    /** @return list<mixed> the `text` of every content block */
    private function texts(\stdClass $result): array
    {
        $content = $result->content ?? null;
        $this->assertIsList($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), $content);
    }

    /** @return list<string> */
    private function keys(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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
