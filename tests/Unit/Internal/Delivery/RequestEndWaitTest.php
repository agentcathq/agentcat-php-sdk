<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Delivery;

use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestEndWaitTest extends TestCase
{
    public function testTheWorkerWaitIsOneSecond(): void
    {
        $this->assertEqualsWithDelta(1.0, RequestEndWait::WORKER_SECONDS, PHP_FLOAT_EPSILON); // D58
    }

    /** @return iterable<string, array{bool, string, array<string, string>, float}> */
    public static function processes(): iterable
    {
        yield 'Octane on Swoole or RoadRunner, a Symfony Runtime worker' => [false, 'cli', [], RequestEndWait::WORKER_SECONDS];
        yield 'a worker under the phpdbg CLI' => [false, 'phpdbg', [], RequestEndWait::WORKER_SECONDS];
        yield 'FrankenPHP worker mode' => [false, 'frankenphp', ['FRANKENPHP_WORKER' => '1'], RequestEndWait::WORKER_SECONDS];
        yield 'FrankenPHP classic mode' => [false, 'frankenphp', [], 0.0];
        yield 'PHP-FPM' => [false, 'fpm-fcgi', [], 0.0];
        yield 'PHP-FPM with a stray worker variable' => [false, 'fpm-fcgi', ['FRANKENPHP_WORKER' => '1'], 0.0];
        yield 'mod_php' => [false, 'apache2handler', [], 0.0];
        yield 'php -S' => [false, 'cli-server', [], 0.0];
        yield 'a console command, artisan mcp:start included' => [true, 'cli', [], 0.0];
        yield 'a console command in a FrankenPHP worker' => [true, 'frankenphp', ['FRANKENPHP_WORKER' => '1'], 0.0];
    }

    /** @param array<string, string> $server */
    #[DataProvider('processes')]
    public function testOnlyAProcessThatOutlivesTheRequestWaits(bool $console, string $sapi, array $server, float $expected): void
    {
        $this->assertSame($expected, RequestEndWait::seconds($console, $sapi, $server, static fn(string $name): false => false));
    }

    public function testFrankenPhpWorkerModeIsAlsoReadFromTheEnvironment(): void
    {
        $getenv = static fn(string $name): string|false => $name === 'FRANKENPHP_WORKER' ? '1' : false;

        $this->assertSame(RequestEndWait::WORKER_SECONDS, RequestEndWait::seconds(false, 'frankenphp', [], $getenv));
    }

    public function testTheDefaultsReadThisProcess(): void
    {
        $this->assertSame('cli', PHP_SAPI);
        $this->assertSame(RequestEndWait::WORKER_SECONDS, RequestEndWait::seconds(false)); // the CLI SAPI, as in an Octane worker
        $this->assertEqualsWithDelta(0.0, RequestEndWait::seconds(true), PHP_FLOAT_EPSILON);
    }

    public function testTheServerVariablesDefaultToThoseOfTheRequest(): void
    {
        $saved = $_SERVER;
        $noEnvironment = static fn(string $name): false => false;

        try {
            unset($_SERVER['FRANKENPHP_WORKER']);
            $this->assertEqualsWithDelta(0.0, RequestEndWait::seconds(false, 'frankenphp', null, $noEnvironment), PHP_FLOAT_EPSILON);
            $_SERVER['FRANKENPHP_WORKER'] = '1';
            $this->assertSame(RequestEndWait::WORKER_SECONDS, RequestEndWait::seconds(false, 'frankenphp', null, $noEnvironment));
            $this->assertEqualsWithDelta(0.0, RequestEndWait::seconds(false, 'frankenphp', [], $noEnvironment), PHP_FLOAT_EPSILON); // given variables win
        } finally {
            $_SERVER = $saved;
        }
    }

    public function testTheWaitLeavesTheShutdownBudgetWhole(): void
    {
        $transport = new RecordingTransport(complete: false); // an API that never answers; the clock is simulated
        $queue = new EventQueue(transport: $transport);
        $queue->add(new QueuedEvent(new Event(projectId: 'proj_' . str_repeat('a', 27))));

        $this->assertFalse($queue->flush(RequestEndWait::WORKER_SECONDS));
        $this->assertSame(RequestEndWait::WORKER_SECONDS, $transport->now());
        $this->assertSame(1, $transport->inFlight()); // still in delivery, not dropped
        $queue->add(new QueuedEvent(new Event(projectId: 'proj_' . str_repeat('a', 27)))); // still accepting
        $queue->shutdown();

        // The worker's stop gets the one 5 s deadline, from its own start: the waits before it used none of it.
        $this->assertSame(RequestEndWait::WORKER_SECONDS + EventQueue::SHUTDOWN_TIMEOUT_SECONDS, $transport->now());
        $this->assertCount(2, $transport->deliveries); // the event added after the wait went out too
    }
}
