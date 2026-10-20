<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\SignalDrain;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * The real drain (`EventQueue::global()`'s) never runs here: each test builds its own `SignalDrain` with its own
 * queue and `raise`. Tests that install handlers get the earlier handlers and the async-signal mode back in
 * `tearDown()`.
 */
final class SignalDrainTest extends TestCase
{
    /** Literals, not the ext-pcntl constants: the `onSignal()` tests also run without ext-pcntl. */
    private const int SIGTERM = 15;

    private const int SIG_DFL = 0;

    private const string DISABLED = 'WARN: AgentCat cannot send waiting events at SIGTERM or SIGINT: ext-pcntl functions are disabled (%s); remove them from disable_functions';

    private const string NO_PCNTL = 'WARN: AgentCat cannot send waiting events at SIGTERM or SIGINT: ext-pcntl is not loaded; install ext-pcntl';

    private const int SIGWINCH = 28;

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private EventQueue $queue;

    /** @var list<array{signal: int, delivered: int}> each `raise` call, with the deliveries made before it */
    private array $raised = [];

    /** @var array<int, (callable(int, mixed): mixed)|int> the handlers before the test */
    private array $savedHandlers = [];

    private ?bool $savedAsyncSignals = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->queue = new EventQueue(transport: $this->transport);
        if (function_exists('pcntl_signal_get_handler')) {
            foreach ([\SIGTERM, \SIGINT, \SIGWINCH] as $signal) {
                $this->savedHandlers[$signal] = pcntl_signal_get_handler($signal);
            }
            $this->savedAsyncSignals = pcntl_async_signals();
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->savedHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        if ($this->savedAsyncSignals !== null) {
            pcntl_async_signals($this->savedAsyncSignals);
        }
        Log::reset();
    }

    #[Group('ac-03-10')]
    public function testWithoutAnEarlierHandlerTheQueueDrainsThenTheSignalIsRaised(): void
    {
        $this->queue->add($this->queued('waiting'));

        $this->drain()->onSignal(self::SIGTERM, self::SIG_DFL);

        $this->assertCount(1, $this->transport->deliveries, 'the waiting event was delivered');
        $this->assertSame([['signal' => self::SIGTERM, 'delivered' => 1]], $this->raised, 'raised once, after the drain');
        $this->queue->add($this->queued('late'));
        $this->assertSame(['Queue is shutting down, event dropped'], $this->logger->messages());
    }

    #[Group('ac-03-11')]
    public function testAnEarlierHandlerIsCalledAfterTheDrainAndNothingIsRaised(): void
    {
        $this->queue->add($this->queued('waiting'));
        $calls = [];
        $customer = function (mixed ...$arguments) use (&$calls): void {
            $calls[] = ['arguments' => $arguments, 'delivered' => count($this->transport->deliveries)];
        };

        $this->drain()->onSignal(self::SIGTERM, $customer, ['signo' => self::SIGTERM, 'pid' => 42]);

        $this->assertSame(
            [['arguments' => [self::SIGTERM, ['signo' => self::SIGTERM, 'pid' => 42]], 'delivered' => 1]],
            $calls,
            'called once, after the drain, with PHP\'s signal information unchanged',
        );
        $this->assertSame([], $this->raised, 'the earlier handler owns termination');
    }

    public function testWithoutSignalInformationTheEarlierHandlerGetsNull(): void
    {
        $calls = [];
        $customer = static function (mixed ...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        };

        $this->drain()->onSignal(self::SIGTERM, $customer);

        $this->assertSame([[self::SIGTERM, null]], $calls);
    }

    public function testASignalHandlerNeverThrows(): void
    {
        $drain = new SignalDrain(
            static fn(): EventQueue => throw new \RuntimeException('no queue'),
            $this->raise(...),
            static fn(string $function): bool => true,
        );

        $drain->onSignal(self::SIGTERM, self::SIG_DFL);

        $this->assertSame([['signal' => self::SIGTERM, 'delivered' => 0]], $this->raised);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testOutsideTheCliSapiNothingIsInstalled(): void
    {
        $this->resetHandlers();

        $this->drain(sapi: 'fpm-fcgi')->install();

        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGTERM));
        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGINT));
        $this->assertFalse(pcntl_async_signals());
    }

    #[RequiresPhpExtension('pcntl')]
    #[DataProvider('pcntlFunctions')]
    public function testWithoutAPcntlFunctionNothingIsInstalled(string $missing): void
    {
        $this->resetHandlers();

        $drain = $this->drain(static fn(string $function): bool => $function !== $missing);
        $drain->install();
        $drain->install();

        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGTERM));
        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGINT));
        $this->assertFalse(pcntl_async_signals());
        $this->assertSame([sprintf(self::DISABLED, $missing)], $this->logger->messages(), 'ext-pcntl is loaded: a host disabled the function');
    }

    public function testTheLineNamesEveryDisabledPcntlFunction(): void
    {
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, $this->raise(...), static fn(string $function): bool => $function === 'pcntl_signal_get_handler', 'cli', static fn(string $extension): bool => $extension === 'pcntl');

        $drain->install();

        $this->assertSame([sprintf(self::DISABLED, 'pcntl_signal, pcntl_async_signals')], $this->logger->messages());
        $this->assertTrue($drain->disable(), 'nothing was installed');
    }

    /** A CLI without ext-pcntl (the official Docker images) has no drain: one line says how to get it. */
    public function testWithoutExtPcntlOneLineSaysToInstallIt(): void
    {
        $asked = new \ArrayObject();
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, $this->raise(...), static function (string $function) use ($asked): bool {
            $asked[] = $function;

            return false;
        }, 'cli', static fn(string $extension): bool => false, osFamily: 'Linux');

        $drain->install();
        $drain->install();

        $this->assertSame([self::NO_PCNTL], $this->logger->messages());
        $this->assertSame(['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals'], $asked->getArrayCopy(), 'checked once');
        $this->assertTrue($drain->disable(), 'nothing was installed');
    }

    /** Windows has no ext-pcntl to install: no line. */
    public function testOnWindowsWithoutExtPcntlNothingIsLogged(): void
    {
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, $this->raise(...), static fn(string $function): bool => false, 'cli', static fn(string $extension): bool => false, osFamily: 'Windows');

        $drain->install();

        $this->assertSame([], $this->logger->messages());
    }

    /** Where the drain never installs (another SAPI, a coroutine server, the switch), no line names the missing extension. */
    public function testWhereTheDrainNeverInstallsNothingIsLogged(): void
    {
        $none = static fn(string $function): bool => false;
        (new SignalDrain(fn(): EventQueue => $this->queue, $this->raise(...), $none, 'fpm-fcgi', static fn(string $extension): bool => false, osFamily: 'Linux'))->install();
        (new SignalDrain(fn(): EventQueue => $this->queue, $this->raise(...), $none, 'cli', static fn(string $extension): bool => $extension === 'swoole', osFamily: 'Linux'))->install();
        $disabled = new SignalDrain(fn(): EventQueue => $this->queue, $this->raise(...), $none, 'cli', static fn(string $extension): bool => false, osFamily: 'Linux');
        $disabled->disable();
        $disabled->install();

        $this->assertSame([], $this->logger->messages());
    }

    /** @return iterable<string, array{string}> */
    public static function posixFunctions(): iterable
    {
        yield 'posix_getpid' => ['posix_getpid'];
        yield 'posix_kill' => ['posix_kill'];
    }

    /**
     * Without one of the two posix functions the default raise exits with the shell's status for the signal. SIGWINCH,
     * whose default disposition is "ignore", shows it: a raise by `posix_kill()` would leave this process running.
     */
    #[DataProvider('posixFunctions')]
    #[RequiresPhpExtension('pcntl')]
    public function testWithoutAPosixFunctionTheRaiseExitsWithTheSignalsStatus(string $missing): void
    {
        $exits = new \ArrayObject();
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, null, static fn(string $function): bool => $function !== $missing, exit: static function (int $status) use ($exits): never {
            $exits[] = $status;

            throw new \LogicException('exit');
        });
        $this->queue->add($this->queued('before-the-exit'));
        pcntl_signal(\SIGWINCH, static function (): void {});

        Thrown::by(\LogicException::class, static fn() => $drain->onSignal(\SIGWINCH, \SIG_DFL));

        $this->assertSame([128 + self::SIGWINCH], $exits->getArrayCopy());
        $this->assertCount(1, $this->transport->deliveries, 'the queue drained before the exit');
        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGWINCH));
    }

    /** @return iterable<string, array{string}> */
    public static function pcntlFunctions(): iterable
    {
        yield 'pcntl_signal' => ['pcntl_signal'];
        yield 'pcntl_signal_get_handler' => ['pcntl_signal_get_handler'];
        yield 'pcntl_async_signals' => ['pcntl_async_signals'];
    }

    #[Group('ac-03-10')]
    #[Group('ac-03-11')]
    #[RequiresPhpExtension('pcntl')]
    public function testInstallChainsEachSignalToItsEarlierHandlerAndTurnsOnAsyncSignals(): void
    {
        $this->resetHandlers();
        $calls = [];
        $customer = static function (mixed ...$arguments) use (&$calls): void {
            $calls[] = $arguments;
        };
        pcntl_signal(\SIGTERM, $customer);

        $this->drain()->install();

        $this->assertTrue(pcntl_async_signals(), 'the stdio loop sleeps: handlers must run without pcntl_signal_dispatch()');
        $onTerm = pcntl_signal_get_handler(\SIGTERM);
        $onInt = pcntl_signal_get_handler(\SIGINT);
        $this->assertInstanceOf(\Closure::class, $onTerm);
        $this->assertInstanceOf(\Closure::class, $onInt);
        $this->assertNotSame($customer, $onTerm);
        $onTerm(\SIGTERM, ['signo' => \SIGTERM]);
        $raisedOnTerm = $this->raised;
        $termHandlerAfter = pcntl_signal_get_handler(\SIGTERM);
        $onInt(\SIGINT, ['signo' => \SIGINT]);
        $this->assertSame([[\SIGTERM, ['signo' => \SIGTERM]]], $calls, 'only SIGTERM had an earlier handler');
        $this->assertSame([], $raisedOnTerm, 'the earlier SIGTERM handler owns termination');
        $this->assertSame([['signal' => \SIGINT, 'delivered' => 0]], $this->raised);
        $this->assertSame($customer, $termHandlerAfter, 'once: a second SIGTERM goes to the earlier handler');
        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGINT), 'once: a second SIGINT gets the default disposition');
    }

    /** Hands each signal back before the drain, so a second Ctrl-C during the drain acts as without AgentCat. */
    #[RequiresPhpExtension('pcntl')]
    public function testTheHandlerGivesTheSignalBackBeforeTheDrain(): void
    {
        $this->resetHandlers();
        $seen = [];
        $queue = function () use (&$seen): EventQueue {
            $seen[] = pcntl_signal_get_handler(\SIGINT);

            return $this->queue;
        };
        (new SignalDrain($queue, $this->raise(...), static fn(string $function): bool => true, 'cli'))->install();
        $onInt = pcntl_signal_get_handler(\SIGINT);
        $this->assertInstanceOf(\Closure::class, $onInt);

        $onInt(\SIGINT, null);

        $this->assertSame([\SIG_DFL], $seen, 'SIG_DFL was back when the drain started');
    }

    #[RequiresPhpExtension('pcntl')]
    public function testInstallTwiceInstallsOnce(): void
    {
        $this->resetHandlers();
        $drain = $this->drain();
        $drain->install();
        $later = static function (): void {};
        pcntl_signal(\SIGTERM, $later);

        $drain->install();

        $this->assertSame($later, pcntl_signal_get_handler(\SIGTERM), 'the second install() changed nothing');
    }

    /** Review Focus 2: the application chose to ignore the signal, so AgentCat leaves it alone. */
    #[RequiresPhpExtension('pcntl')]
    public function testAnIgnoredSignalStaysIgnored(): void
    {
        $this->resetHandlers();
        pcntl_signal(\SIGTERM, \SIG_IGN);

        $this->drain()->install();

        $this->assertSame(\SIG_IGN, pcntl_signal_get_handler(\SIGTERM));
        $this->assertInstanceOf(\Closure::class, pcntl_signal_get_handler(\SIGINT));
    }

    /** Async signals are process wide: with nothing installed, AgentCat leaves the mode alone. */
    #[RequiresPhpExtension('pcntl')]
    public function testWithBothSignalsIgnoredNothingChanges(): void
    {
        $this->resetHandlers();
        pcntl_signal(\SIGTERM, \SIG_IGN);
        pcntl_signal(\SIGINT, \SIG_IGN);

        $this->drain()->install();

        $this->assertSame(\SIG_IGN, pcntl_signal_get_handler(\SIGTERM));
        $this->assertSame(\SIG_IGN, pcntl_signal_get_handler(\SIGINT));
        $this->assertFalse(pcntl_async_signals());
    }

    /**
     * The default `raise`, twice. A forked copy of this process gets SIGTERM and must end by it. This process gets
     * SIGWINCH, whose default disposition is "ignore": it shows the handler reset to `SIG_DFL` and survives.
     */
    #[Group('ac-03-10')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testTheDefaultRaiseEndsTheProcessWithTheDefaultDisposition(): void
    {
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, null, static fn(string $function): bool => true);
        pcntl_signal(\SIGWINCH, static function (): void {});

        $child = pcntl_fork();
        if ($child === 0) {
            try {
                $drain->onSignal(\SIGTERM, \SIG_DFL);
            } finally {
                posix_kill(posix_getpid(), \SIGKILL); // never run the rest of the test suite in the child
            }
        }
        $this->assertGreaterThan(0, $child, 'fork failed');
        $status = 0;
        pcntl_waitpid($child, $status);
        $drain->onSignal(\SIGWINCH, \SIG_DFL);

        $this->assertIsInt($status);
        $this->assertTrue(pcntl_wifsignaled($status), 'the child ended by a signal');
        $this->assertSame(\SIGTERM, pcntl_wtermsig($status));
        $this->assertSame(\SIG_DFL, pcntl_signal_get_handler(\SIGWINCH));
    }

    /** @return iterable<string, array{string}> */
    public static function coroutineExtensions(): iterable
    {
        yield 'swoole' => ['swoole'];
        yield 'openswoole' => ['openswoole'];
    }

    #[DataProvider('coroutineExtensions')]
    #[RequiresPhpExtension('pcntl')]
    public function testNothingIsInstalledUnderACoroutineServer(string $extension): void
    {
        $before = pcntl_signal_get_handler(\SIGTERM);
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, static function (int $signal): void {}, static fn(string $f): bool => true, 'cli', static fn(string $loaded): bool => $loaded === $extension);

        $drain->install();

        $this->assertSame($before, pcntl_signal_get_handler(\SIGTERM));
        $this->assertTrue($drain->disable()); // never installed, so a later switch-off still counts
    }

    /** SIG_DFL on both signals and synchronous dispatch, so that a test sees exactly what `install()` changed. */
    private function resetHandlers(): void
    {
        pcntl_signal(\SIGTERM, \SIG_DFL);
        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_async_signals(false);
    }

    /** @param (\Closure(string): bool)|null $functionExists */
    private function drain(?\Closure $functionExists = null, string $sapi = 'cli'): SignalDrain
    {
        return new SignalDrain(
            fn(): EventQueue => $this->queue,
            $this->raise(...),
            $functionExists ?? static fn(string $function): bool => true,
            $sapi,
        );
    }

    private function raise(int $signal): void
    {
        $this->raised[] = ['signal' => $signal, 'delivered' => count($this->transport->deliveries)];
    }

    private function queued(string $name): QueuedEvent
    {
        return new QueuedEvent(new Event(projectId: 'proj_test', eventType: 'mcp:tools/call', resourceName: $name));
    }

    #[RequiresPhpExtension('pcntl')]
    public function testDisableBeforeInstallKeepsTheHandlersAsTheyWere(): void
    {
        $before = pcntl_signal_get_handler(\SIGTERM);
        $beforeInt = pcntl_signal_get_handler(\SIGINT);
        $async = pcntl_async_signals();
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, static function (int $signal): void {}, static fn(string $f): bool => true, 'cli');

        $this->assertTrue($drain->disable());
        $drain->install();

        $this->assertSame($before, pcntl_signal_get_handler(\SIGTERM));
        $this->assertSame($beforeInt, pcntl_signal_get_handler(\SIGINT));
        $this->assertSame($async, pcntl_async_signals(), 'the switch leaves the async-signal mode alone');
    }

    #[RequiresPhpExtension('pcntl')]
    public function testDisableAfterInstallReportsThatItIsTooLate(): void
    {
        $drain = new SignalDrain(fn(): EventQueue => $this->queue, static function (int $signal): void {}, static fn(string $f): bool => true, 'cli');
        $drain->install();

        $this->assertFalse($drain->disable());
        $this->assertNotSame(\SIG_DFL, pcntl_signal_get_handler(\SIGTERM));
    }
}
