<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Internal\Delivery\RequestEnd;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class RequestEndTest extends TestCase
{
    /** A buffer that the close stops at: removable, so the test can read and remove it, but not flushable. */
    private const int NOT_FLUSHABLE = PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_REMOVABLE;

    /** @var list<string> */
    private array $calls = [];

    /** Session files of the `detect()` tests, under the test HOME (Ruling R8). */
    private string $sessionDir = '';

    /** @var array<string, string> the ini settings a test changed, with their values before it */
    private array $savedIni = [];

    private ?int $savedIgnoreUserAbort = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        foreach ($this->savedIni as $name => $value) {
            ini_set($name, $value);
        }
        if ($this->savedIgnoreUserAbort !== null) {
            ignore_user_abort((bool) $this->savedIgnoreUserAbort);
        }
        if ($this->sessionDir !== '') {
            $files = glob($this->sessionDir . '/*');
            array_map(unlink(...), $files === false ? [] : $files);
            rmdir($this->sessionDir);
        }
    }

    public function testFpmClosesTheSessionAndExtendsTheTimeLimitButKeepsTheBuffers(): void
    {
        $this->end('fpm-fcgi', finishes: true)->prepare(5.0);

        $this->assertSame(['session', 'limit 6'], $this->calls);
    }

    #[Group('ac-03-1')]
    public function testASapiWithoutAFinishFunctionAlsoSendsTheOutputBuffers(): void
    {
        $this->end('apache2handler', finishes: false)->prepare(5.0);

        $this->assertSame(['session', 'limit 6', 'output'], $this->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function commandLineSapis(): iterable
    {
        yield 'cli' => ['cli'];
        yield 'phpdbg' => ['phpdbg'];
    }

    #[DataProvider('commandLineSapis')]
    #[Group('ac-03-37')]
    public function testTheCommandLineIsLeftAlone(string $sapi): void
    {
        $this->end($sapi, finishes: false)->prepare(5.0);

        $this->assertSame([], $this->calls);
    }

    public function testNoSessionAndNoTimeLimitMeanNothingToDo(): void
    {
        $this->end('fpm-fcgi', finishes: true, session: false, limit: 0)->prepare(0.4);

        $this->assertSame([], $this->calls);
    }

    public function testAFractionalBudgetRoundsUp(): void
    {
        $this->end('fpm-fcgi', finishes: true, session: false)->prepare(1.2);

        $this->assertSame(['limit 3'], $this->calls);
    }

    /** @return iterable<string, array{float, int}> */
    public static function budgets(): iterable
    {
        yield 'none' => [0.0, 1];
        yield 'negative' => [-3.5, 1];
        yield 'NaN' => [NAN, 6];
        yield 'INF' => [INF, 6];
        yield '-INF' => [-INF, 6];
        yield 'beyond the int range' => [9.2233720368547758E18, 6];
        yield 'long, inside the int range' => [1.0E18, 1_000_000_000_000_000_001];
    }

    /** A budget that is not finite, or that `(int)` cannot convert, counts as the shutdown timeout (5 s). */
    #[DataProvider('budgets')]
    public function testTheTimeLimitIsTheBudgetPlusOneSecond(float $budget, int $limit): void
    {
        $this->end('fpm-fcgi', finishes: true, session: false)->prepare($budget);

        $this->assertSame(['limit ' . $limit], $this->calls);
    }

    public function testAFailingStepNeverStopsTheOthers(): void
    {
        $end = new RequestEnd(
            'cli-server',
            false,
            sessionActive: static fn(): bool => throw new \RuntimeException('session'),
            closeSession: function (): void {
                $this->calls[] = 'session';
            },
            timeLimit: static fn(): int => throw new \RuntimeException('ini'),
            setTimeLimit: function (int $seconds): void {
                $this->calls[] = 'limit';
            },
            closeOutput: function (): void {
                $this->calls[] = 'output';
            },
        );

        $end->prepare(5.0);

        $this->assertSame(['output'], $this->calls);
    }

    public function testAThrowingCallNeverStopsTheNextStepOrReachesTheCaller(): void
    {
        $end = new RequestEnd(
            'cli-server',
            false,
            sessionActive: static fn(): bool => true,
            closeSession: function (): never {
                $this->calls[] = 'session';

                throw new \RuntimeException('session');
            },
            timeLimit: static fn(): int => 30,
            setTimeLimit: function (int $seconds): never {
                $this->calls[] = 'limit ' . $seconds;

                throw new \RuntimeException('limit');
            },
            closeOutput: function (): never {
                $this->calls[] = 'output';

                throw new \RuntimeException('output');
            },
        );

        $end->prepare(5.0);

        $this->assertSame(['session', 'limit 6', 'output'], $this->calls);
    }

    public function testCloseOutputBuffersSendsWhatTheBuffersAboveTheKeptLevelHold(): void
    {
        $level = ob_get_level(); // PHPUnit's own buffer stays: the test keeps its level
        ob_start();
        ob_start();
        echo 'held';

        RequestEnd::closeOutputBuffers($level);

        $this->assertSame($level, ob_get_level());
        $this->expectOutputString('held');
    }

    /**
     * The shutdown pass keeps no level: PHP's own buffer (`output_buffering`) goes too. Here that is PHPUnit's buffer,
     * empty at this point; the test opens a new one in its place, so PHPUnit finds its level again.
     */
    public function testCloseOutputBuffersSendsEveryRemovableBufferByDefault(): void
    {
        $level = ob_get_level();
        $this->assertGreaterThan(0, $level, "PHPUnit's own buffer");
        ob_start();

        RequestEnd::closeOutputBuffers();
        $closed = ob_get_level();
        while (ob_get_level() < $level) {
            ob_start();
        }

        $this->assertSame(0, $closed);
    }

    /** Symfony's rule: a buffer that may not be flushed, and every buffer below it, stays. */
    public function testCloseOutputBuffersStopsAtABufferThatMayNotBeFlushed(): void
    {
        $level = ob_get_level();
        ob_start();
        echo 'below ';
        ob_start(null, 0, self::NOT_FLUSHABLE);
        echo 'kept ';
        ob_start();
        echo 'sent';

        RequestEnd::closeOutputBuffers($level);

        $this->assertSame($level + 2, ob_get_level());
        $this->assertSame('kept sent', ob_get_clean());
        $this->assertSame('below ', ob_get_clean());
    }

    public function testDetectDoesNothingOnTheCommandLine(): void
    {
        $this->assertSame('cli', PHP_SAPI);
        $this->startSession();
        $this->setIni('max_execution_time', '30');

        RequestEnd::detect(false)->prepare(5.0);

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('30', ini_get('max_execution_time'));
    }

    /**
     * The real functions on a SAPI without a finish call. The close stops at a buffer that may not be flushed, which
     * stands in for PHPUnit's own: the real close sends every removable buffer.
     */
    #[Group('ac-03-1')]
    public function testDetectWritesTheSessionSetsTheTimeLimitAndSendsTheBuffers(): void
    {
        $this->startSession();
        $_SESSION['seen'] = 'yes';
        $this->setIni('max_execution_time', '30');
        $this->saveIgnoreUserAbort(false);
        ob_start(null, 0, self::NOT_FLUSHABLE);
        ob_start();
        echo 'held';

        RequestEnd::detect(false, 'cli-server')->prepare(5.0);

        $this->assertSame('held', ob_get_clean(), 'the open buffer went into the one below');
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $files = glob($this->sessionDir . '/sess_*');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);
        $this->assertStringContainsString('seen|s:3:"yes"', (string) file_get_contents($files[0]));
        $this->assertSame('6', ini_get('max_execution_time'));
        $this->assertSame(1, ignore_user_abort(), 'the client may leave once it has the body');
    }

    /** `disable_functions` and a PHP without ext-session: each step whose function is missing is skipped. */
    public function testDetectSkipsTheStepsWhoseFunctionsAreMissing(): void
    {
        $this->startSession();
        $this->setIni('max_execution_time', '30');
        $this->saveIgnoreUserAbort(false);
        ob_start(null, 0, self::NOT_FLUSHABLE);
        ob_start();
        echo 'held';

        RequestEnd::detect(false, 'cli-server', static fn(string $function): bool => false)->prepare(5.0);

        $this->assertSame('held', ob_get_clean(), 'output buffering is part of PHP itself');
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame('30', ini_get('max_execution_time'));
        $this->assertSame(0, ignore_user_abort());
    }

    /** @return iterable<string, array{string}> */
    public static function sessionFunctions(): iterable
    {
        yield 'session_status' => ['session_status'];
        yield 'session_write_close' => ['session_write_close'];
    }

    #[DataProvider('sessionFunctions')]
    public function testDetectNeedsBothSessionFunctions(string $missing): void
    {
        $this->startSession();

        RequestEnd::detect(true, 'fpm-fcgi', static fn(string $function): bool => $function !== $missing)->prepare(5.0);

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    private function end(string $sapi, bool $finishes, bool $session = true, int $limit = 30): RequestEnd
    {
        return new RequestEnd(
            $sapi,
            $finishes,
            sessionActive: static fn(): bool => $session,
            closeSession: function (): void {
                $this->calls[] = 'session';
            },
            timeLimit: static fn(): int => $limit,
            setTimeLimit: function (int $seconds): void {
                $this->calls[] = 'limit ' . $seconds;
            },
            closeOutput: function (): void {
                $this->calls[] = 'output';
            },
        );
    }

    /** A native session in this CLI process: no cookie, no cache headers, its file under the test HOME. */
    private function startSession(): void
    {
        $home = getenv('HOME');
        $this->assertIsString($home);
        $this->sessionDir = $home . '/sessions-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->sessionDir, 0o700));
        $this->setIni('session.use_cookies', '0');
        $this->setIni('session.cache_limiter', '');
        $this->setIni('session.save_path', $this->sessionDir);
        $this->assertTrue(session_start());
    }

    private function setIni(string $name, string $value): void
    {
        $previous = ini_set($name, $value);
        $this->assertNotFalse($previous, $name);
        $this->savedIni += [$name => $previous];
    }

    private function saveIgnoreUserAbort(bool $value): void
    {
        $this->savedIgnoreUserAbort = ignore_user_abort($value);
    }
}
