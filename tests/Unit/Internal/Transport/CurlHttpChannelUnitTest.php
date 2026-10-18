<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No transfer starts here: nothing is pumped, curl is "missing", or every add is refused. tests/Integration/Transport covers the transfers. */
final class CurlHttpChannelUnitTest extends TestCase
{
    /** A5: at most one line per pass, with the count of that pass. */
    private const string FULL = 'WARN: AgentCat telemetry export queue full, dropped the oldest %d request(s)';

    private const string NO_CURL = 'WARN: AgentCat cannot send telemetry exports or diagnostics: ext-curl functions are missing or disabled (';

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

    public function testAFullQueueDropsTheOldestRequestsWithOneLinePerPass(): void
    {
        // Every add is refused: one pump then fails each waiting request through its callback, and none is sent.
        $swallowed = [];
        $channel = $this->refusingChannel($swallowed);
        $ran = [];
        $post = static function (int $i) use ($channel, &$ran): void {
            $channel->post(new HttpRequest('http://127.0.0.1:9/r' . $i, [], '{}'), static function () use (&$ran, $i): void {
                $ran[] = $i;
            });
        };

        for ($i = 0; $i < CurlHttpChannel::MAX_WAITING + 3; ++$i) {
            $post($i);
        }

        $this->assertSame(1000, $channel->pending());
        $this->assertSame(0, $channel->inTransfer());
        $this->assertSame([], $this->logger->messages(), 'the line waits for the pass');
        $channel->pump();
        $this->assertSame([sprintf(self::FULL, 3)], $this->logger->messages(), 'one line for the pass, with its count');
        $this->assertSame(range(3, 1002), $ran, 'r0 to r2, the oldest, were dropped');
        $this->assertSame(0, $channel->pending());

        $channel->pump();
        $this->assertCount(1, $this->logger->messages(), 'a pass without drops logs nothing');

        for ($i = 0; $i < CurlHttpChannel::MAX_WAITING + 1; ++$i) {
            $post(2000 + $i);
        }
        $channel->pump();
        $this->assertSame([sprintf(self::FULL, 3), sprintf(self::FULL, 1)], $this->logger->messages(), 'the count starts again');
        $this->assertSame([], $swallowed);
    }

    /** A6: the waiting requests hold at most 16 MiB of bodies; the oldest go first, with the same line. */
    public function testTheWaitingBodiesStayWithinTheirByteBudget(): void
    {
        $swallowed = [];
        $channel = $this->refusingChannel($swallowed);
        $mib = str_repeat('x', 1_048_576);
        $ran = [];
        for ($i = 0; $i < 18; ++$i) {
            $channel->post(new HttpRequest('http://127.0.0.1:9/r' . $i, [], $mib), static function () use (&$ran, $i): void {
                $ran[] = $i;
            });
        }

        $this->assertSame(16, $channel->pending(), '16 MiB of bodies');
        $channel->pump();
        $this->assertSame(range(2, 17), $ran, 'r0 and r1, the oldest, were dropped');
        $this->assertSame([sprintf(self::FULL, 2)], $this->logger->messages());

        // The budget is free again once the requests have left the waiting list.
        for ($i = 0; $i < 16; ++$i) {
            $channel->post(new HttpRequest('http://127.0.0.1:9/s' . $i, [], $mib), static function (): void {});
        }
        $this->assertSame(16, $channel->pending());
        $channel->pump();
        $this->assertCount(1, $this->logger->messages(), 'nothing dropped');
        $this->assertSame([], $swallowed);
    }

    /** A6: a body larger than the whole budget still waits, alone: the newest request is never dropped. */
    public function testABodyOverTheBudgetWaitsAlone(): void
    {
        $swallowed = [];
        $channel = $this->refusingChannel($swallowed);
        $ran = [];
        $record = static function (string $name) use (&$ran): \Closure {
            return static function () use ($name, &$ran): void {
                $ran[] = $name;
            };
        };

        $channel->post(new HttpRequest('http://127.0.0.1:9/small', [], '{}'), $record('small'));
        $channel->post(new HttpRequest('http://127.0.0.1:9/huge', [], str_repeat('x', CurlHttpChannel::MAX_WAITING_BYTES + 1)), $record('huge'));
        $this->assertSame(1, $channel->pending());
        $channel->post(new HttpRequest('http://127.0.0.1:9/next', [], '{}'), $record('next'));
        $this->assertSame(1, $channel->pending());

        $channel->pump();
        $this->assertSame(['next'], $ran);
        $this->assertSame([sprintf(self::FULL, 2)], $this->logger->messages());
        $this->assertSame([], $swallowed);
    }

    /** @return iterable<string, array{float, float}> */
    public static function badTimeouts(): iterable
    {
        yield 'connect NAN' => [NAN, 10.0];
        yield 'connect negative' => [-1.0, 10.0];
        yield 'total INF' => [2.0, INF];
        yield 'total zero (curl: no timeout)' => [2.0, 0.0];
    }

    /** M2: a timeout curl cannot take is a programming error; the queue builds the channel with the defaults. */
    #[DataProvider('badTimeouts')]
    public function testATimeoutCurlCannotTakeIsRejectedAtConstruction(float $connectTimeoutSeconds, float $totalTimeoutSeconds): void
    {
        $error = Thrown::by(\InvalidArgumentException::class, static fn(): CurlHttpChannel => new CurlHttpChannel($connectTimeoutSeconds, $totalTimeoutSeconds));

        $this->assertStringStartsWith('A curl timeout must round to 1 through 2147483647 milliseconds, got ', $error->getMessage());
    }

    public function testWithoutACurlFunctionEveryRequestIsDroppedWithOneLine(): void
    {
        $called = new \ArrayObject();
        $channel = new CurlHttpChannel(functionExists: static fn(string $function): bool => $function !== 'curl_multi_init');
        $onDone = static function () use ($called): void {
            $called[] = true;
        };

        $channel->post(new HttpRequest('http://127.0.0.1:9/a', [], '{}'), $onDone);
        $channel->post(new HttpRequest('http://127.0.0.1:9/b', [], '{}'), $onDone);
        $channel->pump();
        $channel->waitForActivity(1.0);

        $this->assertSame(0, $channel->pending());
        $this->assertCount(0, $called);
        $this->assertSame([self::NO_CURL . 'curl_multi_init)'], $this->logger->messages());
    }

    public function testTheLineNamesEveryMissingFunction(): void
    {
        $channel = new CurlHttpChannel(functionExists: static fn(string $function): bool => !in_array($function, ['curl_multi_strerror', 'curl_init'], true));

        $channel->post(new HttpRequest('http://127.0.0.1:9', [], ''), static function (): void {});

        $this->assertSame([self::NO_CURL . 'curl_init, curl_multi_strerror)'], $this->logger->messages());
    }

    public function testTheFunctionsAreCheckedOnceAtTheFirstPost(): void
    {
        $checked = new \ArrayObject();
        $channel = new CurlHttpChannel(functionExists: static function (string $function) use ($checked): bool {
            $checked[] = $function;

            return function_exists($function);
        });
        $channel->pump();
        $this->assertCount(0, $checked);

        $channel->post(new HttpRequest('http://127.0.0.1:9/a', [], ''), static function (): void {});
        $channel->post(new HttpRequest('http://127.0.0.1:9/b', [], ''), static function (): void {});

        $this->assertSame(
            ['curl_init', 'curl_setopt_array', 'curl_error', 'curl_strerror', 'curl_getinfo', 'curl_multi_init', 'curl_multi_add_handle', 'curl_multi_remove_handle', 'curl_multi_exec', 'curl_multi_info_read', 'curl_multi_select', 'curl_multi_strerror'],
            $checked->getArrayCopy(),
        );
        $this->assertSame(2, $channel->pending());
        $this->assertSame([], $this->logger->messages());
    }

    public function testAPumpWithNothingToSendRunsNoCurl(): void
    {
        $calls = new \ArrayObject();
        $multiStatus = static function (string $function, int $code) use ($calls): int {
            $calls[] = $function;

            return $code;
        };
        $channel = new CurlHttpChannel(multiStatus: $multiStatus);
        $missing = new CurlHttpChannel(functionExists: static fn(string $function): bool => $function !== 'curl_multi_init', multiStatus: $multiStatus);
        $missing->post(new HttpRequest('http://127.0.0.1:9', [], ''), static function (): void {});

        $channel->pump();
        $missing->pump();

        $this->assertCount(0, $calls);
        $this->assertSame(0, $channel->pending());
    }

    /** @return iterable<string, array{float}> */
    public static function bounds(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'negative' => [-1.0];
        yield 'one second' => [1.0];
        yield 'INF' => [INF];
    }

    /** A waiting request starts at the next pump(); with nothing in transfer there is nothing to wait for. */
    #[DataProvider('bounds')]
    public function testAWaitWithNothingInTransferReturnsAtOnce(float $seconds): void
    {
        $channel = new CurlHttpChannel();
        $channel->post(new HttpRequest('http://127.0.0.1:9', [], ''), static function (): void {});
        $begin = hrtime(true);

        $channel->waitForActivity($seconds);

        $this->assertLessThan(0.05, (hrtime(true) - $begin) / 1e9);
        $this->assertSame(1, $channel->pending());
        $this->assertSame(0, $channel->inTransfer());
    }

    /** @return iterable<string, array{int, ?string, bool}> */
    public static function outcomes(): iterable
    {
        yield '200' => [200, null, true];
        yield '299' => [299, null, true];
        yield '199' => [199, null, false];
        yield '300' => [300, null, false];
        yield '200 with an error' => [200, 'cURL error 56: Recv failure', false];
    }

    #[DataProvider('outcomes')]
    public function testAResponseIsOkForA2xxStatusWithoutAnError(int $status, ?string $error, bool $ok): void
    {
        $this->assertSame($ok, (new HttpResponse($status, 'R', 'b', $error))->ok());
    }

    public function testAFailedResponseCarriesOnlyItsError(): void
    {
        $response = HttpResponse::failed('x');

        $this->assertSame(0, $response->status);
        $this->assertSame('', $response->reason);
        $this->assertSame('', $response->body);
        $this->assertSame('x', $response->error);
        $this->assertFalse($response->ok());
    }

    public function testAResponseWithoutAnErrorHasANullError(): void
    {
        $response = new HttpResponse(204, 'No Content', '');

        $this->assertNull($response->error);
        $this->assertTrue($response->ok());
    }

    public function testARequestKeepsItsHeadersInOrder(): void
    {
        $request = new HttpRequest('https://example.test/v1/logs', ['b' => '2', 'a' => '1'], '{}');

        $this->assertSame('https://example.test/v1/logs', $request->url);
        $this->assertSame(['b' => '2', 'a' => '1'], $request->headers);
        $this->assertSame('{}', $request->body);
        $this->assertFalse($request->background, 'an exporter request by default');
    }

    /** I1: `flush()` asks for the requests without the background ones (diagnostics). */
    public function testPendingCanLeaveOutTheBackgroundRequests(): void
    {
        $channel = new CurlHttpChannel();
        $channel->post(new HttpRequest('http://127.0.0.1:9/a', [], ''), static function (): void {});
        $channel->post(new HttpRequest('http://127.0.0.1:9/b', [], '', background: true), static function (): void {});
        $channel->post(new HttpRequest('http://127.0.0.1:9/c', [], '', background: true), static function (): void {});

        $this->assertSame(3, $channel->pending());
        $this->assertSame(3, $channel->pending(true));
        $this->assertSame(1, $channel->pending(false));
    }

    /**
     * A channel whose every add is refused: a pump fails each waiting request through its callback, and none is sent.
     *
     * @param list<\Throwable> $swallowed
     */
    private function refusingChannel(array &$swallowed): CurlHttpChannel
    {
        return new CurlHttpChannel(
            multiStatus: static fn(string $function, int $code): int => $function === 'curl_multi_add_handle' ? CURLM_INTERNAL_ERROR : $code,
            onSwallowed: static function (\Throwable $error) use (&$swallowed): void {
                $swallowed[] = $error;
            },
        );
    }
}
