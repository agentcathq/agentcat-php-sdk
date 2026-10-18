<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Logging;

use AgentCat\Internal\Logging\FileLogger;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Logging\LogLine;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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

            /** @param mixed[] $context */
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

            /** @param mixed[] $context */
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

    public function testTheFirstAdoptedLoggerReceivesEveryLaterLine(): void
    {
        Log::reset();
        $first = new MemoryLogger();
        $second = new MemoryLogger();

        Log::adopt($first);
        Log::adopt($second);
        Log::write('hello');

        $this->assertSame(['hello'], $first->messages());
        $this->assertSame([], $second->messages());
    }

    public function testALoggerSetWithSetLoggerWinsOverAnAdoptedOne(): void
    {
        $set = new MemoryLogger();
        $adopted = new MemoryLogger();
        Log::setLogger($set);

        Log::adopt($adopted);
        Log::write('hello');

        $this->assertSame(['hello'], $set->messages());
        $this->assertSame([], $adopted->messages());
    }

    public function testSettingNoLoggerLetsALaterAdoptionWin(): void
    {
        Log::setLogger(new MemoryLogger());
        Log::setLogger(null);
        $adopted = new MemoryLogger();

        Log::adopt($adopted);
        Log::write('hello');

        $this->assertSame(['hello'], $adopted->messages());
    }

    public function testResetForgetsTheAdoptedLogger(): void
    {
        $first = new MemoryLogger();
        Log::adopt($first);
        Log::reset();
        $second = new MemoryLogger();

        Log::adopt($second);
        Log::write('hello');

        $this->assertSame([], $first->messages());
        $this->assertSame(['hello'], $second->messages());
    }

    public function testHasLoggerTellsWhetherALoggerWasChosen(): void
    {
        $this->assertFalse(Log::hasLogger());
        $directory = getenv('HOME') . '/logtest-' . bin2hex(random_bytes(6)); // under the test HOME
        mkdir($directory);
        $home = Environment::clear('HOME');
        putenv('HOME=' . $directory);
        try {
            Log::write('to the file fallback');
            $this->assertFalse(Log::hasLogger()); // the fallback does not count
            $this->assertFileExists($directory . '/agentcat.log');
            Log::reset();
        } finally {
            $home->restore();
            FileTree::remove($directory);
        }
        Log::adopt(new MemoryLogger());
        $this->assertTrue(Log::hasLogger());
        Log::reset();
        $this->assertFalse(Log::hasLogger());
        Log::setLogger(new MemoryLogger());
        $this->assertTrue(Log::hasLogger());
        Log::setLogger(null);
        $this->assertFalse(Log::hasLogger());
        Log::divert(new MemoryLogger());
        $this->assertTrue(Log::hasLogger());
        Log::endDiversion();
        $this->assertFalse(Log::hasLogger());
    }

    public function testADiversionTakesEveryLineAndBlocksAdoption(): void
    {
        $set = new MemoryLogger();
        Log::setLogger($set);
        $first = new MemoryLogger();
        $second = new MemoryLogger();

        Log::divert($first);
        Log::write('one');
        Log::divert($second);
        Log::adopt(new MemoryLogger());
        Log::write('two');
        Log::endDiversion();
        Log::write('three');

        $this->assertSame(['one'], $first->messages());
        $this->assertSame(['two'], $second->messages());
        $this->assertSame(['three'], $set->messages(), 'the routing from before the first diversion');
    }

    public function testEndingADiversionReopensAdoption(): void
    {
        Log::divert(new MemoryLogger());
        Log::endDiversion();
        Log::endDiversion(); // without a diversion: nothing changes
        $adopted = new MemoryLogger();

        Log::adopt($adopted);
        Log::write('hello');

        $this->assertSame(['hello'], $adopted->messages());
    }

    public function testResetForgetsADiversion(): void
    {
        $set = new MemoryLogger();
        Log::setLogger($set);
        Log::divert(new MemoryLogger());
        Log::reset();
        $adopted = new MemoryLogger();
        Log::adopt($adopted);

        Log::endDiversion();
        Log::write('hello');

        $this->assertSame(['hello'], $adopted->messages(), 'nothing to end after reset()');
        $this->assertSame([], $set->messages());
    }

    public function testTheFileFallbackDoesNotStopALaterAdoption(): void
    {
        $home = sys_get_temp_dir() . '/agentcat-logtest-' . bin2hex(random_bytes(6));
        mkdir($home);
        $previous = getenv('HOME');
        putenv('HOME=' . $home);
        Log::reset();
        $adopted = new MemoryLogger();

        try {
            Log::write('to the file');
            Log::adopt($adopted);
            Log::write('to the adopted logger');

            $this->assertSame(['to the adopted logger'], $adopted->messages());
            $this->assertStringEndsWith('] to the file' . "\n", (string) file_get_contents($home . '/agentcat.log'));
        } finally {
            $previous === false ? putenv('HOME') : putenv('HOME=' . $previous);
            @unlink($home . '/agentcat.log');
            rmdir($home);
        }
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

    public function testTheSinkGetsTheLineBeforeTheLogger(): void
    {
        /** @var \ArrayObject<int, string> $order */
        $order = new \ArrayObject();
        Log::setLogger(new class ($order) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $order */
            public function __construct(private readonly \ArrayObject $order) {}

            /** @param mixed[] $context */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->order[] = 'logger: ' . $message;
            }
        });
        Log::setDiagnosticsSink(static function (string $line) use ($order): void {
            $order[] = $line;
        });

        Log::write('hello');

        $this->assertSame([LogLine::prefix() . ' hello', 'logger: hello'], $this->withoutTime($order, 1));
    }

    public function testEveryLevelReachesTheSink(): void
    {
        Log::setLogger(new MemoryLogger());
        $lines = $this->recordSink();

        Log::error('a');
        Log::warning('b');
        Log::info('c');
        Log::write('d');

        $this->assertSame([LogLine::prefix() . ' a', LogLine::prefix() . ' b', LogLine::prefix() . ' c', LogLine::prefix() . ' d'], $this->withoutTime($lines));
    }

    #[Group('ac-06-60')]
    public function testAThrowingSinkNeverStopsTheLogger(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        Log::setDiagnosticsSink(static function (): never {
            throw new \RuntimeException('sink');
        });

        Log::write('hello');

        $this->assertSame(['hello'], $logger->messages());
    }

    #[Group('ac-03-36')]
    public function testWithoutAHomeTheSinkStillGetsTheLine(): void
    {
        Log::setLogger(new FileLogger(null, static fn(): ?string => null));
        $lines = $this->recordSink();

        Log::write('hello');

        $this->assertSame([LogLine::prefix() . ' hello'], $this->withoutTime($lines));
        $this->assertMatchesRegularExpression('/\A\[sdk=\S+ php=\S+ mcp=\S+(?: laravel-mcp=\S+)?\]\z/', LogLine::prefix());
    }

    /** D38: lines that go to the PSR-3 logger of the tracked server reach the sink too. */
    public function testAnAdoptedLoggerDoesNotHideLinesFromTheSink(): void
    {
        $adopted = new MemoryLogger();
        Log::adopt($adopted);
        $lines = $this->recordSink();

        Log::write('one');
        Log::write('two');

        $this->assertSame(['one', 'two'], $adopted->messages());
        $this->assertSame([LogLine::prefix() . ' one', LogLine::prefix() . ' two'], $this->withoutTime($lines));
    }

    /** Spec conflict 9: while `AgentCat::fake()` diverts the log, diagnostics get nothing. */
    public function testADivertedLineNeverReachesTheSink(): void
    {
        Log::setLogger(new MemoryLogger());
        $lines = $this->recordSink();
        $fake = new MemoryLogger();

        Log::divert($fake);
        Log::write('diverted');
        Log::endDiversion();
        Log::write('after');

        $this->assertSame(['diverted'], $fake->messages());
        $this->assertSame([LogLine::prefix() . ' after'], $this->withoutTime($lines));
    }

    public function testASecondSinkReplacesTheFirstAndNullRemovesIt(): void
    {
        Log::setLogger(new MemoryLogger());
        $first = $this->recordSink();
        $second = $this->recordSink();

        Log::write('one');
        Log::setDiagnosticsSink(null);
        Log::write('two');

        $this->assertSame([], $this->withoutTime($first));
        $this->assertSame([LogLine::prefix() . ' one'], $this->withoutTime($second));
    }

    public function testResetRemovesTheSink(): void
    {
        $lines = $this->recordSink();
        Log::reset();
        Log::setLogger(new MemoryLogger());

        Log::write('hello');

        $this->assertSame([], $this->withoutTime($lines));
    }

    /** @return \ArrayObject<int, string> the lines the new sink receives */
    private function recordSink(): \ArrayObject
    {
        /** @var \ArrayObject<int, string> $lines */
        $lines = new \ArrayObject();
        Log::setDiagnosticsSink(static function (string $line) use ($lines): void {
            $lines[] = $line;
        });

        return $lines;
    }

    /**
     * The first `$timed` lines (all by default) with their `[<ISO ms Z>] ` stamp checked and removed; the rest as is.
     *
     * @param \ArrayObject<int, string> $lines
     *
     * @return list<string>
     */
    private function withoutTime(\ArrayObject $lines, ?int $timed = null): array
    {
        $out = [];
        foreach (array_values($lines->getArrayCopy()) as $index => $line) {
            if ($timed === null || $index < $timed) {
                $this->assertMatchesRegularExpression('/\A\[\d{4}-\d\d\-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] /', $line);
                $line = substr($line, 27);
            }
            $out[] = $line;
        }

        return $out;
    }
}
