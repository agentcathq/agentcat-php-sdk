<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class LogTest extends TestCase
{
    protected function tearDown(): void
    {
        Log::reset();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function levelProvider(): iterable
    {
        yield 'error prefix' => ['ERROR: Tool "x" broke', 'error'];
        yield 'fail lower' => ['it did fail here', 'error'];
        yield 'fail upper' => ['FAILED', 'error'];
        yield 'warn prefix' => ['WARN: careful', 'warning'];
        yield 'warning substring' => ['Note Warning: careful', 'warning'];
        yield 'warning and failed' => ['Warning: it failed', 'error'];
        yield 'warn not at start' => ['x WARN: careful', 'info'];
        yield 'plain' => ['Initializing', 'info'];
    }

    #[DataProvider('levelProvider')]
    public function testInfersLevelAndKeepsMessage(string $message, string $level): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        Log::write($message);

        $this->assertSame([['level' => $level, 'message' => $message]], $logger->records);
    }

    public function testWriteSwallowsLoggerExceptions(): void
    {
        $logger = new class extends AbstractLogger {
            public int $calls = 0;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ++$this->calls;

                throw new \RuntimeException('boom');
            }
        };
        Log::setLogger($logger);

        Log::write('hello');

        $this->assertSame(1, $logger->calls);
    }

    public function testResetRemovesCustomLogger(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        Log::reset();
        Log::setLogger(null);

        Log::write('Initializing');

        $this->assertSame([], $logger->records);
    }
}
