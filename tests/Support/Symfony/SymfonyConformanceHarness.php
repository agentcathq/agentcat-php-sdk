<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Symfony\Internal\BuilderConfigurator;
use AgentCat\Symfony\Internal\ServerTracker;
use AgentCat\Tests\Integration\Conformance\ConformanceHarness;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingExporter;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\ReusedId;
use AgentCat\Tests\Support\ToolCalls;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\Assert;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The symfony/mcp-bundle leg of the adapter conformance suite: the `conformance` server of a `TestKernel`, tracked by
 * `AgentCatBundle` with the agentcat configuration the test gives, which sends AgentCat's lines to the `LOGGER`
 * service. The bundle's client takes the global queue when it tracks its first server, so the harness sets a queue
 * that records what its exporter gets before each kernel boots. One kernel answers every request in the session of its
 * handshake as `harness-client` 1.0.0; a fresh harness boots a new kernel for each request and sends a 2026-07-28
 * request, as PHP-FPM gives each request its own container.
 */
final class SymfonyConformanceHarness implements ConformanceHarness
{
    /** The service ID of the logger that the agentcat configuration (`logger`) gives AgentCat's lines. */
    public const string LOGGER = 'conformance.agentcat_logger';

    /** The services of a conformance kernel: AgentCat's logger. */
    public const array SERVICES = [self::LOGGER => MemoryLogger::class];

    private readonly RecordingExporter $exporter;

    private readonly EventQueue $queue;

    /** @var list<MemoryLogger> the logger of each kernel, in the order of the boots */
    private array $loggers = [];

    /** The one server; null when fresh. */
    private HttpHarness|SymfonyHttp|null $server = null;

    /** @param (\Closure(): TestKernel)|null $boot boots a kernel; null: the harness made its server itself */
    private function __construct(private readonly ?\Closure $boot)
    {
        $this->exporter = new RecordingExporter();
        $this->queue = new EventQueue(transport: new RecordingTransport(), exporters: $this->exporter);
    }

    /**
     * The `conformance` server of the kernels that `$boot` boots: one, after the handshake, which tracks the server
     * with this harness's queue; a new kernel per request when `$fresh`.
     *
     * @param \Closure(): TestKernel $boot
     */
    public static function of(\Closure $boot, bool $fresh = false): self
    {
        $harness = new self($boot);
        if (!$fresh) {
            $harness->server = $harness->kernel();
            $harness->server->initialize();
        }

        return $harness;
    }

    /**
     * The conformance server, tracked by the bundle's configurator after an earlier one, which a tracker with tracing
     * off had tracked and built, was freed: each `ServerTracker` serves one container, as each request of PHP-FPM has
     * its own. Asserts that the new Builder has the old one's object ID. The harness makes both Builders, with the five
     * tools of `Tools/`.
     *
     * @param array<string, mixed> $agentcat the agentcat configuration of the new server, which names `LOGGER`
     * @param string               $logFile  the tracker's file of AgentCat's lines without a logger
     */
    public static function reusedObjectId(array $agentcat, string $logFile): self
    {
        $harness = new self(null);
        $logger = new MemoryLogger();
        $harness->loggers[] = $logger;
        EventQueue::setGlobal($harness->queue);
        $old = self::builder();
        $harness->configurator(['enable_tracing' => false] + $agentcat, $logger, $logFile)->configure($old);
        $old->build();
        $id = spl_object_id($old);
        $gone = \WeakReference::create($old);
        unset($old);
        gc_collect_cycles(); // AgentCat's handlers and the Builder hold each other
        Assert::assertNull($gone->get(), 'the old Builder is freed');
        $held = ReusedId::hold($id);

        $builder = $held->reuse(self::builder(...));

        $harness->configurator($agentcat, $logger, $logFile)->configure($builder);
        $harness->server = new HttpHarness($builder->build());
        $harness->server->initialize();

        return $harness;
    }

    /** The `Mcp-Session-Id` of the handshake; null when fresh. */
    public function sessionId(): ?string
    {
        return $this->server?->sessionId();
    }

    #[\Override]
    public function listTools(): \stdClass
    {
        return $this->server?->listTools() ?? HttpHarness::result($this->kernel()->modern('tools/list', []));
    }

    #[\Override]
    public function callTool(string $name, array $arguments, array $meta = []): \stdClass
    {
        $server = $this->server ?? $this->kernel();
        if ($meta !== []) {
            // modern() adds the protocol version and the client capabilities; the client info is in $meta, if anywhere.
            return $server->modern('tools/call', ['name' => $name, 'arguments' => (object) $arguments, '_meta' => $meta], $name, null);
        }

        return $this->server === null ? $server->modern('tools/call', HttpHarness::callParams($name, $arguments, null), $name) : $server->callTool($name, $arguments);
    }

    #[\Override]
    public function events(): array
    {
        Assert::assertTrue($this->queue->flush(), 'flush');

        return $this->exporter->events;
    }

    #[\Override]
    public function received(string $tool): array
    {
        return ToolCalls::of($tool);
    }

    #[\Override]
    public function lines(): array
    {
        return array_merge(...array_map(static fn(MemoryLogger $logger): array => $logger->messages(), $this->loggers));
    }

    /** Boots a kernel, whose tracker will take this harness's queue; its route of the `conformance` server. */
    private function kernel(): SymfonyHttp
    {
        if (!$this->boot instanceof \Closure) {
            throw new \LogicException('This harness boots no kernel');
        }
        EventQueue::setGlobal($this->queue);
        $kernel = ($this->boot)();
        $logger = $kernel->getContainer()->get(self::LOGGER);
        Assert::assertInstanceOf(MemoryLogger::class, $logger);
        $this->loggers[] = $logger;

        return new SymfonyHttp($kernel);
    }

    /**
     * The bundle's configurator of a `conformance` builder, with a tracker of its own, as one container has.
     *
     * @param array<string, mixed> $agentcat
     */
    private function configurator(array $agentcat, MemoryLogger $logger, string $logFile): BuilderConfigurator
    {
        $services = new ServiceLocator([self::LOGGER => static fn(): MemoryLogger => $logger]);

        return new BuilderConfigurator('conformance', new ServerTracker(static fn(): \ArrayObject => new \ArrayObject($agentcat), $services, null, $logFile, ['conformance']), []);
    }

    /** A Builder of `conformance-server` 2.0.0 with the tools of `TestKernel::TOOLS`, which it creates first. */
    private static function builder(): Builder
    {
        $builder = Server::builder()->setServerInfo('conformance-server', '2.0.0');
        foreach (TestKernel::TOOLS as $name => $class) {
            $builder->addTool([$class, '__invoke'], $name);
        }

        return $builder;
    }
}
