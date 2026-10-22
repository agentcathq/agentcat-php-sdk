<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Diagnostics;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\MemoryLogger;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;

/** A9 (D49): a blocked collector holds the shutdown only for the short diagnostics timeouts. */
final class DiagnosticsTimeoutsTest extends TestCase
{
    private Environment $environment;

    /** @var list<resource> the listening socket and the connections that fill its backlog */
    private array $sockets = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->environment = Environment::clear('AGENTCAT_DISABLE_DIAGNOSTICS', 'AGENTCAT_DIAGNOSTICS_ENDPOINT', 'AGENTCAT_DIAGNOSTICS_TOKEN', 'DISABLE_DIAGNOSTICS', 'DIAGNOSTICS_ENDPOINT', 'DIAGNOSTICS_TOKEN');
        Diagnostics::reset();
        Log::setLogger(new MemoryLogger());
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        Diagnostics::reset();
        Log::reset();
        $this->environment->restore();
    }

    /**
     * A firewall that drops the packets to otel.agentcat.com: the connection never opens. The shutdown waits for the
     * batch only until the 250 ms connect timeout (exporters keep 2 s), not for its 5 s budget.
     */
    #[MaximumDuration(3000)]
    public function testABlackHoledCollectorEndsTheShutdownWaitAfterTheConnectTimeout(): void
    {
        putenv('AGENTCAT_DIAGNOSTICS_ENDPOINT=' . $this->blackHole());
        $channel = new CurlHttpChannel();
        $queue = new EventQueue(channel: $channel);
        Diagnostics::init('proj_ABC', false, $queue, static fn(): bool => false);
        Log::write('one');
        $started = hrtime(true);

        $queue->shutdown();

        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual(0.2, $elapsed, 'the shutdown waited for the batch');
        $this->assertLessThan(Diagnostics::CONNECT_TIMEOUT_SECONDS + 1.0, $elapsed, 'and stopped at the 250 ms connect timeout');
        $this->assertSame(0, $channel->pending(), 'the batch failed at its timeout, not abandoned at the deadline');
    }

    /**
     * A local endpoint that drops connection attempts: a listening socket with a backlog of 0 and one connection that
     * fills its accept queue, so Linux drops every further SYN and a client's connect waits for its timeout. Nothing
     * leaves 127.0.0.1.
     */
    private function blackHole(): string
    {
        $context = stream_context_create(['socket' => ['backlog' => 0]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $this->assertIsResource($server, $errorMessage ?? '');
        $this->sockets[] = $server;
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $filler = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 1.0); // connected: the queue is full
        $this->assertIsResource($filler, $errorMessage ?? '');
        $this->sockets[] = $filler;

        return 'http://' . $address;
    }
}
