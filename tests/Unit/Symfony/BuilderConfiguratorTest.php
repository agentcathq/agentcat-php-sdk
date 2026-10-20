<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Symfony;

use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Symfony\Internal\BuilderConfigurator;
use AgentCat\Symfony\Internal\ServerTracker;
use AgentCat\Tests\Support\MemoryLogger;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Plan E2 header conflicts 1 and 2: the configurator the builder had runs first, then AgentCat tracks, then the
 * customer's tagged handlers are added. Each step writes a line, so their order is the order of the lines.
 */
final class BuilderConfiguratorTest extends TestCase
{
    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        Log::setLogger($this->logger = new MemoryLogger());
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
        TrackedServers::resetIncomplete(); // the tracker's failure marks the process
    }

    public function testThePreviousConfiguratorRunsFirstThenTheTrackerThenTheHandlers(): void
    {
        $builder = Server::builder();
        $previous = static function (Builder $configured) use ($builder): void {
            Log::write($configured === $builder ? 'previous' : 'previous got another builder');
        };

        (new BuilderConfigurator('main', $this->tracker(), [$this->handlers('first'), 'not iterable', $this->handlers('second')], $previous))->configure($builder);

        $this->assertSame(['previous', 'Warning: Failed to track server - RuntimeException: no configuration', 'handlers first', 'handlers second'], $this->logger->messages());
    }

    public function testWithoutAPreviousConfiguratorTheTrackerRunsFirst(): void
    {
        (new BuilderConfigurator('main', $this->tracker(), [$this->handlers('only')], 'not a function'))->configure(Server::builder());

        $this->assertSame(['Warning: Failed to track server - RuntimeException: no configuration', 'handlers only'], $this->logger->messages());
    }

    /** A tracker whose configuration cannot be read: it logs one line and leaves the builder as it is. */
    private function tracker(): ServerTracker
    {
        return new ServerTracker(static fn(): never => throw new \RuntimeException('no configuration'), new ServiceLocator([]), null, '/nonexistent/agentcat.log');
    }

    /**
     * Handlers that write a line when the Builder takes them.
     *
     * @return \Generator<int, never>
     */
    private function handlers(string $name): \Generator
    {
        Log::write('handlers ' . $name);

        yield from [];
    }
}
