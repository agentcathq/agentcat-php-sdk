<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\E2E\Support\StdioMcpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hosts that disable process, signal, curl multi and clock functions (`disable_functions`: on PHP 8 a disabled
 * function does not exist). A tracked stdio server answers as it would without AgentCat and ends cleanly at stdin EOF;
 * AgentCat logs at most one line for each function it misses, a function whose absence turns a feature off is named in
 * exactly one line, and diagnostics, on as outside tests, still reach their collector while ext-curl is whole.
 */
final class HardenedHostTest extends TestCase
{
    private const string HANDSHAKE_PROTOCOL_VERSION = '2025-06-18';

    /**
     * The 5 s shutdown budget, plus room for a slow runner: the event API is a closed port, so delivery retries until the
     * budget ends (measured 5.1 s from stdin EOF).
     */
    private const float EOF_EXIT_SECONDS = 6.5;

    /** The functions whose absence turns a feature off: the signal drain, or event delivery. */
    private const array REPORTED = [
        'pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals',
        'curl_multi_init', 'curl_multi_exec', 'curl_multi_add_handle', 'curl_multi_select',
    ];

    private static ?MockCollector $collector = null;

    /** The server's HOME (its agentcat.log), under the test HOME. */
    private string $home = '';

    /** The collector scenario of the server's diagnostics. */
    private string $diagnostics = '';

    private ?StdioMcpClient $client = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->home = getenv('HOME') . '/hardened-host-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->home, 0o700));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->client?->process()->stop(0);
        $this->client = null;
        FileTree::remove($this->home);
    }

    /** @return iterable<string, array{string}> */
    public static function lists(): iterable
    {
        yield 'shared hosting' => ['exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,posix_kill,posix_mkfifo,set_time_limit,ignore_user_abort,putenv,getmypid,ini_set,parse_ini_file,show_source,highlight_file'];
        yield 'no signals' => ['pcntl_signal,pcntl_signal_get_handler,pcntl_signal_dispatch,pcntl_async_signals,posix_getpid,posix_kill'];
        yield 'no curl multi' => ['curl_multi_init,curl_multi_exec,curl_multi_add_handle,curl_multi_select'];
        yield 'no clocks' => ['hrtime,getenv'];
        yield 'shared hosting without curl multi or signals' => ['curl_multi_exec,pcntl_signal,set_time_limit,ignore_user_abort,putenv,getmypid'];
    }

    #[DataProvider('lists')]
    #[Group('hardened-host')]
    #[MaximumDuration(10000)]
    public function testTheServerAnswersAndEndsWithOneLinePerMissingFunction(string $functions): void
    {
        $client = $this->start($functions);
        $initialize = $this->resultOf($client, 1, 'initialize', [
            'protocolVersion' => self::HANDSHAKE_PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'hardened-client', 'version' => '1.0.0'],
        ]);
        $this->assertSame(self::HANDSHAKE_PROTOCOL_VERSION, JsonAssert::at($initialize, 'protocolVersion'));
        $client->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $call = $this->resultOf($client, 2, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hardened']]);
        $this->assertFalse(JsonAssert::at($call, 'isError'));
        $this->assertContains('echo: hardened', $this->texts($call));

        $client->closeInput();
        $code = $client->waitForExit(self::EOF_EXIT_SECONDS);
        $stderr = $client->process()->getErrorOutput();
        $this->assertSame(0, $code, 'exit code at stdin EOF; stderr: ' . $stderr);
        $this->assertStringNotContainsString('Fatal error', $stderr);
        $this->assertStringNotContainsString('Uncaught', $stderr);
        foreach ($client->stdoutLines() as $line) {
            $this->assertSame('2.0', JsonAssert::at(Json::decode($line), 'jsonrpc'), 'stdout carries JSON-RPC only: ' . $line);
        }

        $posts = $this->collector()->requests($this->diagnostics);
        if (str_contains($functions, 'curl_')) {
            $this->assertSame([], $posts, 'without curl multi nothing is sent');
        } else {
            $this->assertNotSame([], $posts, 'diagnostics reach the collector');
            $this->assertStringContainsString('AgentCat setup complete', MockCollector::body($posts[0]));
        }

        $entries = $this->logEntries();
        $this->assertNotSame([], preg_grep('/AgentCat setup complete/', $entries), 'the log works: ' . implode('', $entries));
        $this->assertSame([], preg_grep('/Call to undefined function/', $entries), 'AgentCat calls no disabled function');
        foreach (explode(',', $functions) as $function) {
            $naming = preg_grep('/\b' . preg_quote($function, '/') . '\b/', $entries);
            $this->assertIsArray($naming);
            if (in_array($function, self::REPORTED, true)) {
                $this->assertCount(1, $naming, $function . ' is named in one line: ' . implode('', $entries));
            } else {
                $this->assertLessThanOrEqual(1, count($naming), $function . ' is named in one line at most: ' . implode('', $entries));
            }
        }
    }

    /**
     * Starts the fixture server with `$functions` disabled. The event API is a closed local port; diagnostics are on, as
     * they are by default outside tests, and go to this test's collector scenario.
     */
    private function start(string $functions): StdioMcpClient
    {
        $this->diagnostics = $this->collector()->scenario();
        $this->client = StdioMcpClient::command(
            [PHP_BINARY, '-d', 'disable_functions=' . $functions, '-d', 'display_errors=stderr', '-d', 'log_errors=0', __DIR__ . '/../Fixtures/hardened-host.php'],
            [
                'HOME' => $this->home,
                'AGENTCAT_ENABLED' => 'true',
                'AGENTCAT_API_URL' => MockCollector::closedPortUrl(),
                'AGENTCAT_DIAGNOSTICS_ENDPOINT' => $this->diagnostics,
                'AGENTCAT_DISABLE_DIAGNOSTICS' => 'false',
                'DISABLE_DIAGNOSTICS' => 'false',
            ],
        );

        return $this->client;
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }

    /**
     * Sends one request and returns the `result` of its answer.
     *
     * @param array<string, mixed> $params
     */
    private function resultOf(StdioMcpClient $client, int $id, string $method, array $params): \stdClass
    {
        $client->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        return HttpHarness::result($client->receive($id));
    }

    /** @return list<mixed> the `text` of each content block */
    private function texts(\stdClass $result): array
    {
        $content = JsonAssert::at($result, 'content');
        $this->assertIsArray($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), array_values($content));
    }

    /** @return list<string> the entries of the server's agentcat.log, each starting at its timestamp */
    private function logEntries(): array
    {
        $path = $this->home . '/agentcat.log';
        $log = is_file($path) ? (string) file_get_contents($path) : '';
        $entries = preg_split('/^(?=\[\d{4}-\d\d\-\d\dT)/m', $log);
        $this->assertIsArray($entries);

        return array_values(array_filter($entries, static fn(string $entry): bool => $entry !== ''));
    }
}
