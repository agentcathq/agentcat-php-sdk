<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\E2E\Support\StdioMcpClient;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stdio servers in the PHP CLI image (the official image plus ext-pcntl), the way an MCP host or an orchestrator runs
 * them: `docker run --interactive --init`, so PHP is not PID 1 and a signal with the default disposition ends it.
 * And the signal guard under Swoole.
 */
#[Group('runtime')]
final class CliTest extends RuntimeTestCase
{
    /** How long one `docker run` client may run: the container's start, the calls, the signal and the drain. */
    private const float CLIENT_SECONDS = 60.0;

    /** How long an answer may take: the first one includes the container's start and PHP's boot. */
    private const float ANSWER_SECONDS = 15.0;

    /**
     * How long the container may take to end after the signal: the drain's 5 s budget (D47), and the removal of the
     * container (`--rm`), which a busy Docker host can take seconds for. PHP as PID 1 would never end.
     */
    private const float EXIT_SECONDS = 20.0;

    private const int SIGTERM = 15;

    /** @return iterable<string, array{list<string>, string, int}> the server's command, AgentCat's log in `/out`, and the exit code after the signal */
    public static function stdioServers(): iterable
    {
        yield 'mcp/sdk' => [['php', self::repo() . '/tests/E2E/Fixtures/stdio-server.php'], 'agentcat.log', 128 + self::SIGTERM];
        yield 'laravel/mcp artisan mcp:start' => [['php', self::repo() . '/tests/E2E/Laravel/artisan.php'], 'app/storage/logs/agentcat.log', 128 + self::SIGTERM];
        yield 'symfony/mcp-bundle mcp:server' => [['php', self::repo() . '/tests/Runtime/Fixtures/symfony-console.php', 'mcp:server'], 'symfony/log/agentcat.log', 0];
    }

    /**
     * `03` AC-10 and AC-37: the signal drain delivers the queued event, then raises SIGTERM again with the default
     * disposition, so the container ends with 128 + 15; stdout carries only the JSON-RPC answers. Under Symfony's
     * `mcp:server` the drain chains to the handler that Symfony Console registered, which dispatches its signal event
     * (exit code 0 by default) and calls `exit(0)`: the code is Console's own, 0, and AgentCat never raises the signal.
     *
     * @param list<string> $server
     */
    #[DataProvider('stdioServers')]
    #[Group('ac-03-10')]
    #[Group('ac-03-37')]
    #[MaximumDuration(60000)]
    public function testSigtermDeliversTheQueueOnAStockBuildAndStdoutStaysJsonRpc(array $server, string $log, int $exitCode): void
    {
        $scenario = self::$collector->scenario();
        $out = $this->out();
        $client = StdioMcpClient::command(DockerRuntime::command(Images::cli(), $server, $this->env($scenario), [$out => '/out']), timeout: self::CLIENT_SECONDS);
        // The handshake era: mcp/sdk 0.8.1 serves 2026-07-28 over stdio only on main; laravel/mcp serves both.
        $client->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'cli', 'version' => '1']]]);
        $this->assertSame('2025-06-18', JsonAssert::at(HttpHarness::result($client->receive(1, self::ANSWER_SECONDS)), 'protocolVersion'));
        $client->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $client->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => 'start']]]);
        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($client->receive(2, self::ANSWER_SECONDS)), 'content', 1, 'text'));
        $this->assertSame([], self::$collector->requests($scenario), 'nothing delivered the event before the signal');

        $client->signal(self::SIGTERM); // docker run passes it to the container's init, which passes it to PHP

        $this->assertSame($exitCode, $client->waitForExit(self::EXIT_SECONDS), $client->process()->getErrorOutput());
        $this->assertCount(1, self::$collector->requests($scenario));
        $this->assertSame(1, substr_count($this->outFile($log), MockCollector::SENT_EVENT), 'the drain had the delivery\'s answer before PHP ended');
        $lines = $client->stdoutLines();
        $this->assertCount(2, $lines, 'the answers to initialize and tools/call');
        foreach ($lines as $line) {
            $this->assertSame('2.0', JsonAssert::at(Json::decode($line), 'jsonrpc'), $line);
        }
    }

    /**
     * ext-swoole stops its workers with C-level signal handlers that PHP cannot see or
     * chain, so the first event installs no SIGTERM handler there; the same script in the CLI image installs one. Both
     * images have ext-pcntl, so the guard, not a missing extension, keeps the handler out.
     */
    #[MaximumDuration(60000)]
    public function testNoSignalHandlerIsInstalledUnderSwoole(): void
    {
        $script = ['php', self::repo() . '/tests/Runtime/Fixtures/swoole-guard.php'];
        $out = $this->out();

        $swoole = DockerRuntime::run(Images::swoole(), $script, $this->env(), [$out => '/out']);
        $plain = DockerRuntime::run(Images::cli(), $script, $this->env(), [$out => '/out']);

        $this->assertSame(0, $swoole['exitCode'], $swoole['stderr']);
        $this->assertEquals((object) ['swoole' => true, 'pcntl' => true, 'agentcatHandler' => false], Json::decode($swoole['stdout']), $swoole['stderr']);
        $this->assertSame(0, $plain['exitCode'], $plain['stderr']);
        $this->assertEquals((object) ['swoole' => false, 'pcntl' => true, 'agentcatHandler' => true], Json::decode($plain['stdout']), $plain['stderr']);
    }
}
