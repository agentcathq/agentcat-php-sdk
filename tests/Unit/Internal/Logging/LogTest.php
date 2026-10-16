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
    #[\Override]
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
        yield 'Error prefix' => ['Error: Tool "x" broke', 'error'];
        yield 'fail word is not an error' => ['it did fail here', 'info'];
        yield 'FAILED is not an error' => ['FAILED', 'info'];
        yield 'warn prefix' => ['WARN: careful', 'warning'];
        yield 'warning substring' => ['Note Warning: careful', 'warning'];
        yield 'warning and failed' => ['Warning: it failed', 'warning'];
        yield 'handle injection failed' => ['WARN: Handle injection failed for tool "x"; listing it unmodified - boom', 'warning'];
        yield 'context injection failed' => ['WARN: Context injection failed for tool "x"; listing it unmodified - boom', 'warning'];
        yield 'tool-list injection failed' => ['Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - boom', 'warning'];
        yield 'warn about get_errors tool' => ['WARN: Tool "get_errors" has complex schema (oneOf/allOf/anyOf). Skipping handle injection.', 'warning'];
        yield 'invalid tag error_code' => ['Dropping invalid tag: "error_code" — reason', 'info'];
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

    public function testExplicitMethodsUseTheirLevelRegardlessOfText(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        Log::error('plain text');
        Log::warning('ERROR: looks like an error');
        Log::info('WARN: looks like a warning');

        $this->assertSame([
            ['level' => 'error', 'message' => 'plain text'],
            ['level' => 'warning', 'message' => 'ERROR: looks like an error'],
            ['level' => 'info', 'message' => 'WARN: looks like a warning'],
        ], $logger->records);
    }

    public function testExplicitMethodsSwallowLoggerExceptions(): void
    {
        $logger = new class extends AbstractLogger {
            public int $calls = 0;

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ++$this->calls;

                throw new \RuntimeException('boom');
            }
        };
        Log::setLogger($logger);

        Log::error('a');
        Log::warning('b');
        Log::info('c');

        $this->assertSame(3, $logger->calls);
    }

    public function testWriteSwallowsLoggerExceptions(): void
    {
        $logger = new class extends AbstractLogger {
            public int $calls = 0;

            #[\Override]
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

    public function testResetFallsBackToFileLoggerInHome(): void
    {
        $home = sys_get_temp_dir() . '/agentcat-logtest-' . bin2hex(random_bytes(6));
        mkdir($home);
        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        Log::reset();

        try {
            Log::write('Initializing');
            $this->assertSame([], $logger->records);
            $this->assertStringEndsWith('] Initializing' . "\n", (string) file_get_contents($home . '/agentcat.log'));
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
            @unlink($home . '/agentcat.log');
            rmdir($home);
        }
    }
}
