<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\OncePerObject;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class OncePerObjectTest extends TestCase
{
    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testALineIsLoggedAsAWarningOncePerObject(): void
    {
        $server = new \stdClass();

        OncePerObject::warn($server, 'drift');
        OncePerObject::warn($server, 'drift');

        $this->assertSame([['level' => LogLevel::WARNING, 'message' => 'drift']], $this->logger->records);
    }

    public function testEachObjectAndEachLineCountsOnItsOwn(): void
    {
        $one = new \stdClass();
        $two = new \stdClass();

        OncePerObject::warn($one, 'drift');
        OncePerObject::warn($two, 'drift');
        OncePerObject::warn($one, 'other');
        OncePerObject::warn($two, 'drift');

        $this->assertSame(['drift', 'drift', 'other'], $this->logger->messages());
    }
}
