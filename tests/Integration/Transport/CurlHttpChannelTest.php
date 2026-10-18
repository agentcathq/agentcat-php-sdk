<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Transport;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Transport\CurlHttpChannel;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\MemoryLogger;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CurlHttpChannelTest extends TestCase
{
    private const string MULTI_ERROR = 'cURL multi error 4: Internal error';

    private static ?MockCollector $collector = null;

    private MemoryLogger $logger;

    /** @var list<\Throwable> what the channels of this test swallowed (I2: the normal paths swallow nothing) */
    private array $swallowed = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
    }

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
        $this->assertSame([], array_map(ErrorText::of(...), $this->swallowed), 'a channel swallowed a throwable');
    }

    #[MaximumDuration(3000)]
    public function testAPostCarriesItsHeadersAndBodyAndReportsTheAnswer(): void
    {
        $url = $this->collector()->scenario([['status' => 404, 'body' => 'nope']]);
        $channel = $this->channel();
        $responses = [];
        $channel->post(
            new HttpRequest($url . '/v1/traces', ['Content-Type' => 'application/json', 'x-api-key' => 'k'], '{"a":1}'),
            static function (HttpResponse $response) use (&$responses): void {
                $responses[] = $response;
            },
        );

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertCount(1, $responses);
        $this->assertSame(404, $responses[0]->status);
        $this->assertSame('Not Found', $responses[0]->reason);
        $this->assertSame('nope', $responses[0]->body);
        $this->assertNull($responses[0]->error);
        $this->assertFalse($responses[0]->ok());
        $requests = $this->collector()->requests($url);
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $this->assertSame('POST', $request->method);
        $this->assertSame('/v1/traces', $request->path);
        $this->assertSame('{"a":1}', $request->body);
        $this->assertSame('k', $this->header($request, 'x-api-key'));
        $this->assertSame('application/json', $this->header($request, 'Content-Type'));
        $this->assertStringStartsWith('agentcat-php/', (string) $this->header($request, 'User-Agent'));
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-06-63')]
    #[MaximumDuration(3000)]
    public function testARefusedConnectionAndATimeoutAreErrorsNotExceptions(): void
    {
        $slow = $this->collector()->scenario([], ['status' => 200, 'delayMs' => 1000]);
        $channel = $this->channel(1.0, 0.3);
        $errors = [];
        $record = static function (HttpResponse $response) use (&$errors): void {
            $errors[] = $response->error;
        };
        $channel->post(new HttpRequest(MockCollector::closedPortUrl() . '/v1/logs', [], '{}'), $record);
        $channel->post(new HttpRequest($slow . '/v1/logs', [], '{}'), $record);

        $this->assertTrue($this->drain($channel, 2.0));

        sort($errors);
        $this->assertCount(2, $errors);
        $this->assertMatchesRegularExpression('/\AcURL error 28: /', (string) $errors[0]);
        $this->assertMatchesRegularExpression('/\AcURL error 7: /', (string) $errors[1]);
        $this->assertSame([], $this->logger->messages(), 'a failed request reaches only its callback');
    }

    /** Review Focus 5: no file is read and no redirect is followed. */
    #[MaximumDuration(3000)]
    public function testOnlyHttpAndHttpsAreFetched(): void
    {
        $target = $this->collector()->scenario();
        $moved = $this->collector()->scenario([['status' => 301, 'body' => '', 'location' => $target . '/followed']]);
        $channel = $this->channel();
        $responses = [];
        $record = static function (HttpResponse $response) use (&$responses): void {
            $responses[] = $response;
        };
        $channel->post(new HttpRequest('file:///etc/hostname', [], ''), $record);
        $channel->post(new HttpRequest($moved . '/batch', [], '{}'), $record);

        $this->assertTrue($this->drain($channel, 2.0));

        $byUrl = [];
        foreach ($responses as $response) {
            $byUrl[$response->error === null ? 'http' : 'file'] = $response;
        }
        $this->assertArrayHasKey('file', $byUrl);
        $this->assertArrayHasKey('http', $byUrl);
        $this->assertMatchesRegularExpression('/\AcURL error 1: /', (string) $byUrl['file']->error);
        $this->assertSame('', $byUrl['file']->body);
        $this->assertSame(301, $byUrl['http']->status);
        $this->assertCount(1, $this->collector()->requests($moved));
        $this->assertSame([], $this->collector()->requests($target), 'the redirect was not followed');
    }

    #[MaximumDuration(3000)]
    public function testACallbackCanPostTheNextRequestOfASequence(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel();
        $order = [];
        $channel->post(new HttpRequest($url . '/one', [], '1'), static function () use ($channel, $url, &$order): void {
            $order[] = 'one';
            $channel->post(new HttpRequest($url . '/two', [], '2'), static function () use (&$order): void {
                $order[] = 'two';
            });
        });

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertSame(['one', 'two'], $order);
    }

    #[MaximumDuration(3000)]
    public function testARequestThatACallbackPostsStartsInTheSamePump(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel();
        $posted = new \ArrayObject();
        $channel->post(new HttpRequest($url . '/one', [], '1'), static function () use ($channel, $url, $posted): void {
            $channel->post(new HttpRequest($url . '/two', [], '2'), static function (): void {});
            $posted[] = true;
        });
        $until = microtime(true) + 2.0;

        do {
            $this->assertLessThan($until, microtime(true), 'the first request did not finish');
            $channel->waitForActivity(0.05);
            $channel->pump();
        } while (count($posted) === 0);

        $this->assertSame(1, $channel->pending());
        $this->assertSame(1, $channel->inTransfer(), 'the posted request is in transfer when the pump returns');
        $this->assertTrue($this->drain($channel, 2.0));
        $this->assertCount(2, $this->collector()->requests($url));
    }

    /**
     * A long-running worker keeps its channel: a finished transfer leaves its handle in no multi handle (the request's
     * callback is freed with it), and the channel is in no reference cycle.
     */
    #[MaximumDuration(3000)]
    public function testAFinishedTransferLeavesNothingBehind(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel();
        $statuses = [];
        $onDone = static function (HttpResponse $response) use (&$statuses): void {
            $statuses[] = $response->status;
        };
        $request = \WeakReference::create($onDone);
        $channel->post(new HttpRequest($url . '/a', [], '{}'), $onDone);
        unset($onDone);

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertSame([202], $statuses);
        $this->assertNull($request->get(), 'the finished handle let go of the request');
        $weak = \WeakReference::create($channel);
        unset($channel);
        $this->assertNull($weak->get(), 'freed without the cycle collector');
    }

    #[MaximumDuration(3000)]
    public function testTheResponseBodyIsCappedAt64KiB(): void
    {
        $url = $this->collector()->scenario([['status' => 500, 'body' => str_repeat('x', 70_000)]]);
        $channel = $this->channel();
        $body = null;
        $channel->post(new HttpRequest($url . '/batch', [], '{}'), static function (HttpResponse $response) use (&$body): void {
            $body = $response->body;
        });

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertSame(CurlHttpChannel::MAX_BODY_BYTES, strlen((string) $body));
    }

    /** I1: a background request in transfer is left out of `pending(false)` too. */
    #[MaximumDuration(3000)]
    public function testABackgroundRequestInTransferIsLeftOutOfTheForegroundCount(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'delayMs' => 300]);
        $channel = $this->channel();
        $channel->post(new HttpRequest($url . '/v1/logs', [], '{}', background: true), static function (): void {});
        $channel->post(new HttpRequest($url . '/batch', [], '{}'), static function (): void {});
        $channel->post(new HttpRequest($url . '/v1/traces', [], '{}'), static function (): void {});
        $channel->pump();

        $this->assertSame(3, $channel->inTransfer());
        $this->assertSame(3, $channel->pending());
        $this->assertSame(2, $channel->pending(false));
        $this->assertTrue($this->drain($channel, 2.0));
    }

    /**
     * I2 and the N1 ruling: a signal handler (the SIGTERM drain) that runs inside a callback pumps, waits on and posts
     * to the channel it interrupted. The `header` seam runs where PHP 8.4+ runs a signal, at the callback's entry before
     * it marks the channel busy: the nested pump's add and run are refused, the refusal marks the channel busy, and
     * the request waits. The `write` seam runs inside the body, where the channel is busy already. Either way: nothing
     * pending, no wait, nothing failed, and every request completes.
     */
    #[MaximumDuration(5000)]
    public function testAPumpFromInsideATransferCallbackLeavesTheChannelAlone(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 200_000)]);
        $responses = [];
        $record = static function (string $name) use (&$responses): \Closure {
            return static function (HttpResponse $response) use ($name, &$responses): void {
                $responses[$name] = [$response->status, $response->error];
            };
        };
        $nested = [];
        $channel = null;
        $channel = $this->channel(onCallback: static function (string $kind) use (&$channel, &$nested, $url, $record): void {
            if (isset($nested[$kind]) || !$channel instanceof CurlHttpChannel) {
                return;
            }
            $begin = hrtime(true);
            $channel->post(new HttpRequest($url . '/' . $kind, [], ''), $record($kind));
            $channel->pump();
            for ($i = 0; $i < 3; ++$i) {
                $channel->waitForActivity(1.0); // 3 s if the channel waited
            }
            $nested[$kind] = [(hrtime(true) - $begin) / 1e9 < 1.5, $channel->pending(), $channel->pending(false), $channel->inTransfer()];
        });
        $channel->post(new HttpRequest($url . '/a', [], ''), $record('a'));

        $this->assertTrue($this->drain($channel, 3.0));

        ksort($responses);
        $this->assertSame(['a' => [200, null], 'header' => [200, null], 'write' => [200, null]], $responses, 'no curl error, no lost transfer');
        ksort($nested);
        $this->assertSame(['header' => [true, 0, 0, 2], 'write' => [true, 0, 0, 2]], $nested, 'in each callback: no wait, nothing pending; a and the refused add of header in transfer');
        $this->assertCount(3, $this->collector()->requests($url));
    }

    /**
     * A callback that ends early (a throwable, or `exit()` in a signal handler) leaves the busy flag set. Outside
     * `curl_multi_exec()` the channel sees that and goes on: the next request is sent as usual.
     */
    #[MaximumDuration(5000)]
    public function testABusyFlagThatACallbackLeftSetIsClearedOutsideCurl(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'body' => str_repeat('x', 200_000)]);
        /** @var \ArrayObject<int, \Throwable> $thrown */
        $thrown = new \ArrayObject();
        $failure = new \RuntimeException('a callback that ends early');
        $channel = new CurlHttpChannel(onSwallowed: static function (\Throwable $error) use ($thrown): void {
            $thrown[] = $error;
        }, onCallback: static function (string $kind) use ($thrown, $failure): void {
            if ($kind === 'write' && count($thrown) === 0) {
                throw $failure;
            }
        });
        $responses = [];
        $record = static function (string $name) use (&$responses): \Closure {
            return static function (HttpResponse $response) use ($name, &$responses): void {
                $responses[$name] = $response->status;
            };
        };
        $channel->post(new HttpRequest($url . '/a', [], ''), $record('a'));
        $this->assertTrue($this->drain($channel, 3.0));
        $this->assertSame([$failure], $thrown->getArrayCopy(), 'the throwable reached only the channel');

        $channel->post(new HttpRequest($url . '/b', [], ''), $record('b'));

        $this->assertSame(1, $channel->pending(), 'not busy outside curl');
        $this->assertTrue($this->drain($channel, 3.0));
        $this->assertSame(200, $responses['b'] ?? null, 'and the next request went out');
    }

    /**
     * php -S hands the 12 requests to its workers unevenly (3 to 8 at once): 200 ms each drains in 0.6-1.2 s. Its 8
     * workers cannot show the cap, so the channel's own count is checked on every pass.
     */
    #[MaximumDuration(6000)]
    public function testAtMostTenTransfersRunAtOnce(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'delayMs' => 200]);
        $channel = $this->channel();
        for ($i = 0; $i < 12; ++$i) {
            $channel->post(new HttpRequest($url . '/r' . $i, [], '{}'), static function (): void {});
        }

        $channel->pump();

        $this->assertSame(12, $channel->pending());
        $this->assertSame(CurlHttpChannel::MAX_CONCURRENT, $channel->inTransfer());
        $until = microtime(true) + 5.0;
        while ($channel->pending() > 0) {
            $this->assertLessThan($until, microtime(true), 'the requests did not finish');
            $channel->waitForActivity(0.05);
            $channel->pump();
            $this->assertLessThanOrEqual(CurlHttpChannel::MAX_CONCURRENT, $channel->inTransfer());
        }
        $this->assertCount(12, $this->collector()->requests($url));
    }

    /** Ruling P11: a multi handle that cannot run fails its transfers, so a flush without a deadline cannot hang. */
    #[MaximumDuration(3000)]
    public function testAFailingExecFailsEveryTransferInsteadOfWaitingForever(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel(
            multiStatus: static fn(string $function, int $code): int => $function === 'curl_multi_exec' ? CURLM_INTERNAL_ERROR : $code,
        );
        $errors = [];
        $record = static function (HttpResponse $response) use (&$errors): void {
            $errors[] = $response->error;
        };
        $channel->post(new HttpRequest($url . '/a', [], '{}'), $record);
        $channel->post(new HttpRequest($url . '/b', [], '{}'), $record);

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertSame([self::MULTI_ERROR, self::MULTI_ERROR], $errors);
        $this->assertSame([], $this->logger->messages());
    }

    #[MaximumDuration(3000)]
    public function testAfterAFailedExecTheFailedTransfersNeverReachTheServer(): void
    {
        $url = $this->collector()->scenario();
        $execs = new \ArrayObject();
        $channel = $this->channel(multiStatus: static function (string $function, int $code) use ($execs): int {
            if ($function !== 'curl_multi_exec') {
                return $code;
            }
            $execs[] = $code;

            return count($execs) === 1 ? CURLM_INTERNAL_ERROR : $code;
        });
        $errors = [];
        $failed = static function (HttpResponse $response) use (&$errors): void {
            $errors[] = $response->error;
        };
        $requests = [\WeakReference::create($failed)];
        $channel->post(new HttpRequest($url . '/a', [], '{}'), $failed);
        $channel->post(new HttpRequest($url . '/b', [], '{}'), $failed);
        unset($failed);

        $channel->pump();
        $this->assertSame(0, $channel->pending());
        $channel->post(new HttpRequest($url . '/c', [], '{}'), static function (HttpResponse $response) use (&$errors): void {
            $errors[] = $response->error;
        });
        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertSame([self::MULTI_ERROR, self::MULTI_ERROR, null], $errors);
        // One curl_multi_exec() opens the connections but sends nothing. The failed handles left the multi handle:
        // they were never sent, and nothing holds their requests any more (either check alone loses a race).
        $received = $this->collector()->requests($url);
        $this->assertCount(1, $received);
        $this->assertSame('/c', $received[0]->path);
        $this->assertNull($requests[0]->get());
    }

    #[MaximumDuration(3000)]
    public function testAFailedAddFailsThatRequestOnly(): void
    {
        $url = $this->collector()->scenario();
        $adds = new \ArrayObject();
        $channel = $this->channel(multiStatus: static function (string $function, int $code) use ($adds): int {
            if ($function !== 'curl_multi_add_handle') {
                return $code;
            }
            $adds[] = $code;

            return count($adds) === 1 ? CURLM_INTERNAL_ERROR : $code;
        });
        $responses = [];
        $refused = static function (HttpResponse $response) use (&$responses): void {
            $responses[] = $response;
        };
        $request = \WeakReference::create($refused);
        $channel->post(new HttpRequest($url . '/refused', [], '{}'), $refused);
        $channel->post(new HttpRequest($url . '/sent', [], '{}'), static function (HttpResponse $response) use (&$responses): void {
            $responses[] = $response;
        });
        unset($refused);

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertCount(2, $responses);
        $this->assertSame(self::MULTI_ERROR, $responses[0]->error);
        $this->assertSame(202, $responses[1]->status);
        // The refused handle left the multi handle again: it was never sent, and nothing holds its request any more.
        $requests = $this->collector()->requests($url);
        $this->assertCount(1, $requests);
        $this->assertSame('/sent', $requests[0]->path);
        $this->assertNull($request->get());
    }

    /** `$onDone` runs once for every request: one that curl refuses to set up fails through its callback too. */
    #[MaximumDuration(3000)]
    public function testARequestCurlCannotSetUpFailsThroughItsCallback(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel();
        $responses = [];
        $record = static function (HttpResponse $response) use (&$responses): void {
            $responses[] = $response;
        };
        $channel->post(new HttpRequest($url . "/nul\0one", [], '{}'), $record);
        $channel->post(new HttpRequest($url . "/nul\0two", [], '{}'), $record);
        $channel->post(new HttpRequest($url . '/next', [], '{}'), $record);

        $channel->pump();

        $this->assertSame(1, $channel->inTransfer(), 'the next request started in the same pass');
        $this->assertTrue($this->drain($channel, 2.0));
        $this->assertCount(3, $responses);
        $nul = 'ValueError: curl_setopt_array(): cURL option must not contain any null bytes';
        $this->assertSame([$nul, $nul, null], array_map(static fn(HttpResponse $response): ?string => $response->error, $responses));
        $this->assertSame(202, $responses[2]->status);
        $this->assertCount(1, $this->collector()->requests($url));
    }

    /**
     * M2: curl_setopt_array() stops at the first option it rejects (libcurl takes no URL over 8 MB). Such a request
     * fails through its callback and is never added; the options that take the response come first, so nothing of
     * any response reaches STDOUT (the stdio MCP stream; PHPUnit fails a test that prints).
     */
    #[MaximumDuration(3000)]
    public function testARequestWithARejectedOptionFailsAndPrintsNothing(): void
    {
        $url = $this->collector()->scenario([], ['status' => 200, 'body' => 'a response body']);
        $channel = $this->channel();
        $responses = [];
        $record = static function (HttpResponse $response) use (&$responses): void {
            $responses[] = $response;
        };
        $channel->post(new HttpRequest($url . '/' . str_repeat('a', 8_000_001), [], '{}'), $record);
        $channel->post(new HttpRequest($url . '/next', [], '{}'), $record);

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertCount(2, $responses);
        $this->assertSame('cURL error: curl_setopt_array() failed', $responses[0]->error);
        $this->assertSame('a response body', $responses[1]->body);
        $requests = $this->collector()->requests($url);
        $this->assertCount(1, $requests);
        $this->assertSame('/next', $requests[0]->path);
    }

    /** I2: a throwable inside a pump never reaches the caller; the seam sees it, and the next pump goes on. */
    #[MaximumDuration(3000)]
    public function testAThrowableInAPumpIsSwallowedAndTheTransfersGoOn(): void
    {
        $url = $this->collector()->scenario();
        $execs = new \ArrayObject();
        $channel = $this->channel(multiStatus: static function (string $function, int $code) use ($execs): int {
            if ($function === 'curl_multi_exec') {
                $execs[] = $code;
                if (count($execs) === 1) {
                    throw new \LogicException('a broken multi handle');
                }
            }

            return $code;
        });
        $statuses = [];
        $channel->post(new HttpRequest($url . '/a', [], '{}'), static function (HttpResponse $response) use (&$statuses): void {
            $statuses[] = $response->status;
        });

        $channel->pump();

        $this->assertSame(['LogicException: a broken multi handle'], array_map(ErrorText::of(...), $this->swallowed));
        $this->swallowed = [];
        $this->assertSame(1, $channel->inTransfer());
        $this->assertTrue($this->drain($channel, 2.0));
        $this->assertSame([202], $statuses);
        $this->assertSame([], $this->logger->messages());
    }

    #[MaximumDuration(3000)]
    public function testAThrowingCallbackDoesNotStopTheOthers(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel();
        $statuses = [];
        $channel->post(new HttpRequest($url . '/a', [], '{}'), static function (): never {
            throw new \RuntimeException('a broken callback');
        });
        $channel->post(new HttpRequest($url . '/b', [], '{}'), static function (HttpResponse $response) use (&$statuses): void {
            $statuses[] = $response->status;
        });

        $this->assertTrue($this->drain($channel, 2.0));

        $this->assertSame([202], $statuses);
        $this->assertSame([], $this->logger->messages());
        $this->assertSame(['RuntimeException: a broken callback'], array_map(ErrorText::of(...), $this->swallowed));
        $this->swallowed = [];
    }

    /** D32: a warning in a callback reaches neither the host's error handler nor `error_get_last()`. */
    #[MaximumDuration(3000)]
    public function testAWarningInACallbackStaysInsideTheChannel(): void
    {
        $url = $this->collector()->scenario();
        $channel = $this->channel();
        $finished = new \ArrayObject();
        $channel->post(new HttpRequest($url . '/a', [], '{}'), static function () use ($finished): void {
            trigger_error('exporter warning', E_USER_WARNING);
            $finished[] = true;
        });
        set_error_handler(static fn(int $no, string $str): never => throw new \ErrorException($str, 0, $no));

        try {
            error_clear_last();

            $this->assertTrue($this->drain($channel, 2.0));

            $this->assertCount(1, $finished, 'the callback ran to its end');
            $this->assertNull(error_get_last());

            try {
                trigger_error('x', E_USER_WARNING);
                self::fail('the host error handler is no longer installed');
            } catch (\ErrorException $exception) {
                $this->assertSame('x', $exception->getMessage());
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Ruling P7: only a wait with a transfer in progress reaches the NaN guard. Without the guard, PHP 8.4+ computes
     * a NAN bound (`max(0.0, NAN)`), which curl_multi_select() rejects with a ValueError: tearDown() sees it swallowed.
     * CurlSupportTest pins the bound itself.
     */
    #[MaximumDuration(3000)]
    public function testAWaitWithANanOrNegativeBoundReturnsAtOnceDuringATransfer(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($server, 'no local server: ' . $errorMessage);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $channel = $this->channel();
        $errors = [];
        $channel->post(new HttpRequest('http://' . $address . '/v1/logs', [], '{}'), static function (HttpResponse $response) use (&$errors): void {
            $errors[] = $response->error;
        });
        $channel->pump();
        $connection = stream_socket_accept($server, 2.0);
        $this->assertNotFalse($connection);
        stream_set_blocking($connection, false);
        $received = '';
        $until = microtime(true) + 2.0;
        while (!str_ends_with($received, "\r\n\r\n{}")) { // the whole request; the server never answers it
            $this->assertLessThan($until, microtime(true), 'the request did not arrive: ' . $received);
            $channel->pump();
            $channel->waitForActivity(0.05);
            $received .= (string) fread($connection, 8192);
        }
        $this->assertSame(1, $channel->inTransfer());

        foreach ([NAN, -1.0] as $seconds) {
            $begin = hrtime(true);
            $channel->waitForActivity($seconds);
            $this->assertLessThan(0.5, (hrtime(true) - $begin) / 1e9);
        }
        $begin = hrtime(true);
        $channel->waitForActivity(0.2);
        $this->assertGreaterThanOrEqual(0.15, (hrtime(true) - $begin) / 1e9, 'a positive bound does block');

        fclose($connection);
        fclose($server);
        $this->assertTrue($this->drain($channel, 2.0));
        $this->assertCount(1, $errors);
        $this->assertMatchesRegularExpression('/\AcURL error \d+: /', (string) $errors[0]);
    }

    /**
     * @param (\Closure(string, int): int)|null $multiStatus
     * @param (\Closure(string): void)|null     $onCallback
     */
    private function channel(
        float $connectTimeoutSeconds = CurlTransport::CONNECT_TIMEOUT_SECONDS,
        float $totalTimeoutSeconds = CurlTransport::TOTAL_TIMEOUT_SECONDS,
        ?\Closure $multiStatus = null,
        ?\Closure $onCallback = null,
    ): CurlHttpChannel {
        return new CurlHttpChannel($connectTimeoutSeconds, $totalTimeoutSeconds, multiStatus: $multiStatus, onSwallowed: function (\Throwable $error): void {
            $this->swallowed[] = $error;
        }, onCallback: $onCallback);
    }

    private function drain(HttpChannel $channel, float $seconds): bool
    {
        $until = microtime(true) + $seconds;
        while (true) {
            $channel->pump();
            if ($channel->pending() === 0) {
                return true;
            }
            if (microtime(true) >= $until) {
                return false;
            }
            $channel->waitForActivity(0.05);
        }
    }

    private function header(\stdClass $request, string $name): ?string
    {
        $headers = $request->headers ?? null;
        $this->assertInstanceOf(\stdClass::class, $headers);
        foreach (get_object_vars($headers) as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $this->assertIsString($value);

                return $value;
            }
        }

        return null;
    }

    private function collector(): MockCollector
    {
        $this->assertInstanceOf(MockCollector::class, self::$collector);

        return self::$collector;
    }
}
