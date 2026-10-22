<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\RepeatedLines;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class RepeatedLinesTest extends TestCase
{
    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        RepeatedLines::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        RepeatedLines::reset();
        Log::reset();
    }

    public function testOutsideADeliveryEveryLineIsLogged(): void
    {
        RepeatedLines::write('Failed: boom');
        RepeatedLines::write('Failed: boom');

        $this->assertSame(['Failed: boom', 'Failed: boom'], $this->logger->messages());
    }

    public function testInsideADeliveryARepeatedLineIsLoggedOnceAndCountedAtTheEnd(): void
    {
        RepeatedLines::begin();
        RepeatedLines::write('Failed: boom');
        RepeatedLines::write('Failed: other');
        RepeatedLines::write('Failed: boom');
        RepeatedLines::write('Failed: boom');
        $this->assertSame(['Failed: boom', 'Failed: other'], $this->logger->messages());

        RepeatedLines::end();

        $this->assertSame(['Failed: boom', 'Failed: other', 'Failed: boom (2 more times in this delivery)'], $this->logger->messages());
    }

    public function testANestedDeliveryReportsAtTheOutermostEnd(): void
    {
        RepeatedLines::begin();
        RepeatedLines::begin();
        RepeatedLines::write('Failed: boom');
        RepeatedLines::write('Failed: boom');
        RepeatedLines::end();
        $this->assertSame(['Failed: boom'], $this->logger->messages());
        RepeatedLines::write('Failed: boom');

        RepeatedLines::end();

        $this->assertSame(['Failed: boom', 'Failed: boom (2 more times in this delivery)'], $this->logger->messages());
    }

    public function testTheNextDeliveryLogsTheLineAgain(): void
    {
        RepeatedLines::begin();
        RepeatedLines::write('Failed: boom');
        RepeatedLines::end();
        RepeatedLines::begin();
        RepeatedLines::write('Failed: boom');
        RepeatedLines::end();

        $this->assertSame(['Failed: boom', 'Failed: boom'], $this->logger->messages(), 'no repeat, no count line');
    }

    public function testAnEndWithoutABeginChangesNothing(): void
    {
        RepeatedLines::end();
        RepeatedLines::write('Failed: boom');
        RepeatedLines::write('Failed: boom');
        RepeatedLines::begin();
        RepeatedLines::write('Failed: next');
        RepeatedLines::write('Failed: next');
        RepeatedLines::end();

        $this->assertSame(['Failed: boom', 'Failed: boom', 'Failed: next', 'Failed: next (1 more times in this delivery)'], $this->logger->messages());
    }
}
