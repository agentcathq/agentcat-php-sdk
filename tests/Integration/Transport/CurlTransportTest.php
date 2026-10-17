<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Transport;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Transport\ApiBaseUrl;
use AgentCat\Internal\Transport\CurlTransport;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Internal\Transport\RetryPolicy;
use AgentCat\Internal\Transport\Transport;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\MemoryLogger;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class CurlTransportTest extends TestCase
{
    private const string SUCCESS = 'Successfully sent event evt_x | mcp:tools/call | session ses_s | proj_p | 12 ms | anonymous';

    private const string FAILED = 'Failed to send event evt_x, retrying... [Error: ';

    private const string GAVE_UP = 'Failed to deliver AgentCat event after retries: ';

    /** Seconds; every blocking call in these tests has a deadline. */
    private const float DEADLINE = 5.0;

    private static ?MockCollector $collector = null;

    private MemoryLogger $logger;

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
    }

    #[Group('ac-03-1')]
    #[Group('ac-07-4')]
    #[Group('ac-07-5')]
    #[MaximumDuration(1000)]
    public function testSendsOnePostWithTheSdkHeaders(): void
    {
        $base = self::collector()->scenario();
        $delivery = $this->delivery($base);

        $this->assertTrue($this->fast()->send($delivery, $this->deadline()));

        $requests = self::collector()->requests($base);
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $this->assertSame('POST', $request->method);
        $this->assertSame('/v1/public/events/', $request->path);
        $this->assertSame('application/json', $this->header($request, 'Content-Type'));
        $this->assertSame('application/json', $this->header($request, 'Accept'));
        $this->assertSame(CurlTransport::userAgent(), $this->header($request, 'User-Agent'));
        $this->assertSame('agentcat-php/' . SdkVersion::get() . ' php/' . PHP_VERSION, CurlTransport::userAgent());
        $this->assertNull($this->header($request, 'Authorization'));
        $this->assertNull($this->header($request, 'Expect'));
        $this->assertSame($delivery->body, $request->body);
    }

    #[Group('ac-07-4')]
    #[MaximumDuration(2000)]
    public function testLargeBodyIsSentWithoutExpectContinue(): void
    {
        // libcurl adds `Expect: 100-continue` above 1 MB (8.x) or 1 KB (7.x) unless the header is blanked.
        $base = self::collector()->scenario();
        $body = '{"id":"evt_x","pad":"' . str_repeat('x', 1_100_000) . '"}';
        $delivery = new Delivery(ApiBaseUrl::endpoint($base), $body, 'evt_x', 'summary');

        $this->assertTrue($this->fast()->send($delivery, $this->deadline()));

        $requests = self::collector()->requests($base);
        $this->assertCount(1, $requests);
        $this->assertNull($this->header($requests[0], 'Expect'));
        $this->assertSame($body, $requests[0]->body);
        $this->assertSame(['Successfully sent event evt_x | summary'], $this->logger->messages());
    }

    #[Group('ac-07-8')]
    #[Group('ac-03-5')]
    #[MaximumDuration(1000)]
    public function testAcceptedEventLogsTheTypeScriptSuccessLine(): void
    {
        $base = self::collector()->scenario();
        $transport = $this->fast();

        $this->assertTrue($transport->send($this->delivery($base), $this->deadline()));

        $this->assertCount(1, self::collector()->requests($base));
        $this->assertSame([self::SUCCESS], $this->logger->messages());
        $this->assertSame(0, $transport->inFlight());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function successStatuses(): iterable
    {
        yield '200' => [200];
        yield '201' => [201];
        yield '204' => [204];
        yield '299' => [299];
    }

    #[DataProvider('successStatuses')]
    #[MaximumDuration(1000)]
    public function testEvery2xxStatusIsASuccess(int $status): void
    {
        $base = self::collector()->scenario([], ['status' => $status, 'body' => '']);

        $this->assertTrue($this->fast()->send($this->delivery($base), $this->deadline()));

        $this->assertCount(1, self::collector()->requests($base));
        $this->assertSame([self::SUCCESS], $this->logger->messages());
    }

    #[Group('ac-07-9')]
    #[MaximumDuration(1000)]
    public function testAcceptedWithEmptyBodyIsDelivered(): void
    {
        $base = self::collector()->scenario([], ['status' => 202, 'body' => '']);

        $this->assertTrue($this->fast()->send($this->delivery($base), $this->deadline()));

        $this->assertCount(1, self::collector()->requests($base));
        $this->assertSame([self::SUCCESS], $this->logger->messages());
    }

    #[Group('ac-07-12')]
    #[Group('ac-03-4')]
    #[MaximumDuration(2000)]
    public function testServerErrorIsRetriedThreeTimesWithTheSameBody(): void
    {
        $base = self::collector()->scenario([], ['status' => 503, 'body' => '{"error":"Server overloaded, please retry later"}']);
        $delivery = $this->delivery($base);
        $transport = $this->fast();

        // true: the delivery settled before the deadline (here by giving up).
        $this->assertTrue($transport->send($delivery, $this->deadline()));

        $requests = self::collector()->requests($base);
        $this->assertCount(4, $requests);
        foreach ($requests as $request) {
            $this->assertSame($delivery->body, $request->body);
        }
        $failed = self::FAILED . 'HTTP 503]';
        $this->assertSame([$failed, $failed, $failed, $failed, self::GAVE_UP . 'HTTP 503'], $this->logger->messages());
        $this->assertSame(0, $transport->inFlight());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function nonSuccessStatuses(): iterable
    {
        yield '400 missing project' => [400, '{"error":"project_id is required"}'];
        yield '401 invalid token' => [401, '{"detail":"Invalid token."}'];
        yield '403 html' => [403, '<html><body><h1>403 Forbidden</h1></body></html>'];
        yield '404' => [404, '{"detail":"Not found."}'];
        yield '405' => [405, '{"detail":"Method \"GET\" not allowed."}'];
        yield '300 (redirects are not followed)' => [300, ''];
    }

    /** D15: TypeScript retries every error, 4xx included. */
    #[Group('ac-07-10')]
    #[Group('ac-07-11')]
    #[DataProvider('nonSuccessStatuses')]
    #[MaximumDuration(2000)]
    public function testOtherStatusesAreRetriedLikeTypeScript(int $status, string $body): void
    {
        $base = self::collector()->scenario([], ['status' => $status, 'body' => $body]);

        $this->assertTrue($this->fast()->send($this->delivery($base), $this->deadline()));

        $this->assertCount(4, self::collector()->requests($base));
        $failed = self::FAILED . 'HTTP ' . $status . ']';
        $this->assertSame([$failed, $failed, $failed, $failed, self::GAVE_UP . 'HTTP ' . $status], $this->logger->messages());
    }

    #[Group('ac-03-5')]
    #[MaximumDuration(1000)]
    public function testRetryAfterOneFailureSucceedsWithoutLoggingThePayload(): void
    {
        $base = self::collector()->scenario([['status' => 503, 'body' => '']]);
        $delivery = $this->delivery($base);

        $this->assertTrue($this->fast()->send($delivery, $this->deadline()));

        $requests = self::collector()->requests($base);
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->body, $requests[1]->body);
        $this->assertStringContainsString('"secret-payload"', $delivery->body);
        $this->assertSame([self::FAILED . 'HTTP 503]', self::SUCCESS], $this->logger->messages());
        foreach ($this->logger->messages() as $message) {
            $this->assertStringNotContainsString('secret-payload', $message);
        }
    }

    #[Group('ac-07-12')]
    #[MaximumDuration(2000)]
    public function testConnectionRefusedIsRetried(): void
    {
        $delivery = $this->delivery(MockCollector::closedPortUrl());

        $this->assertTrue($this->fast()->send($delivery, $this->deadline()));

        $messages = $this->logger->messages();
        $this->assertCount(5, $messages);
        foreach (array_slice($messages, 0, 4) as $message) {
            $this->assertMatchesRegularExpression(
                '/\AFailed to send event evt_x, retrying\.\.\. \[Error: cURL error 7: Failed to connect to 127\.0\.0\.1 port \d+.*\]\z/',
                $message,
            );
        }
        $this->assertStringStartsWith(self::GAVE_UP . 'cURL error 7: Failed to connect to 127.0.0.1 port ', $messages[4]);
    }

    #[MaximumDuration(2000)]
    public function testOnlyHttpAndHttpsAreAllowed(): void
    {
        $event = $this->event();
        $delivery = Delivery::forEvent($event, 'file:///etc/hostname', WireEncoder::encode($event));

        $this->assertTrue($this->fast()->send($delivery, $this->deadline()));

        $messages = $this->logger->messages();
        $this->assertCount(5, $messages);
        foreach (array_slice($messages, 0, 4) as $message) {
            $this->assertStringStartsWith(self::FAILED . 'cURL error 1: ', $message);
        }
        $this->assertStringStartsWith(self::GAVE_UP . 'cURL error 1: ', $messages[4]);
    }

    #[Group('ac-07-6')]
    public function testCurlOptions(): void
    {
        $delivery = new Delivery('https://api.agentcat.com/v1/public/events/', '{"id":"evt_x"}', 'evt_x', 'summary');
        $expected = [
            CURLOPT_URL => 'https://api.agentcat.com/v1/public/events/',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{"id":"evt_x"}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Expect:'],
            CURLOPT_USERAGENT => CurlTransport::userAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 10000,
        ];

        $options = (new CurlTransport())->curlOptions($delivery);

        $this->assertEqualsWithDelta(2.0, CurlTransport::CONNECT_TIMEOUT_SECONDS, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(10.0, CurlTransport::TOTAL_TIMEOUT_SECONDS, PHP_FLOAT_EPSILON);
        $this->assertCount(count($expected), $options);
        foreach ($expected as $option => $value) {
            $this->assertArrayHasKey($option, $options);
            $this->assertSame($value, $options[$option], 'curl option ' . $option);
        }
    }

    #[Group('ac-07-6')]
    public function testTimeoutsAreRoundedToMilliseconds(): void
    {
        $delivery = new Delivery('https://x.test/v1/public/events/', '{}', 'evt_x', 'summary');

        $up = (new CurlTransport(connectTimeoutSeconds: 1.2346, totalTimeoutSeconds: 2.0006))->curlOptions($delivery);
        $down = (new CurlTransport(connectTimeoutSeconds: 1.2344, totalTimeoutSeconds: 2.0004))->curlOptions($delivery);

        $this->assertSame([1235, 2001], [$up[CURLOPT_CONNECTTIMEOUT_MS] ?? null, $up[CURLOPT_TIMEOUT_MS] ?? null]);
        $this->assertSame([1234, 2000], [$down[CURLOPT_CONNECTTIMEOUT_MS] ?? null, $down[CURLOPT_TIMEOUT_MS] ?? null]);
    }

    #[Group('ac-07-6')]
    #[MaximumDuration(2000)]
    public function testTotalTimeoutAbortsASlowResponse(): void
    {
        $base = self::collector()->scenario([['status' => 202, 'delayMs' => 2000, 'body' => '']]);
        $transport = new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]), totalTimeoutSeconds: 0.3);
        $started = hrtime(true);

        $this->assertTrue($transport->send($this->delivery($base), $this->deadline()));

        $this->assertLessThan(1.5, (hrtime(true) - $started) / 1e9);
        $messages = $this->logger->messages();
        $this->assertCount(2, $messages);
        $this->assertStringStartsWith(self::FAILED . 'cURL error 28: ', $messages[0]);
        $this->assertSame(self::SUCCESS, $messages[1]);
    }

    #[Group('ac-07-7')]
    #[MaximumDuration(1000)]
    public function testNothingIsWrittenToStdout(): void
    {
        $base = self::collector()->scenario([['status' => 503, 'body' => 'overloaded']]);
        $this->expectOutputString('');

        $this->assertTrue($this->fast()->send($this->delivery($base), $this->deadline()));

        $this->assertSame([self::FAILED . 'HTTP 503]', self::SUCCESS], $this->logger->messages());
    }

    #[Group('ac-07-13')]
    #[MaximumDuration(3000)]
    public function testNoFailureKindThrows(): void
    {
        $failing = [
            'server error' => $this->delivery(self::collector()->scenario([], ['status' => 500, 'body' => '{"error":"Internal server error"}'])),
            'connection refused' => $this->delivery(MockCollector::closedPortUrl()),
            'unsupported protocol' => new Delivery('file:///etc/hostname', '{}', 'evt_x', 'summary'),
            'NUL byte in the URL' => new Delivery("http://127.0.0.1/\0", '{}', 'evt_x', 'summary'),
        ];
        foreach ($failing as $kind => $delivery) {
            $transport = $this->fast();
            $transport->start($delivery);
            $transport->pump();
            $this->assertTrue($transport->drain($this->deadline()), $kind);
            $this->assertTrue($transport->send($delivery, $this->deadline()), $kind);
            $this->assertSame(0, $transport->inFlight(), $kind);
        }
    }

    #[Group('ac-07-13')]
    public function testAnInvalidUrlDropsTheDeliveryWithOneLine(): void
    {
        $transport = $this->fast();

        $transport->start(new Delivery("http://127.0.0.1/\0", '{}', 'evt_x', 'summary'));

        $this->assertSame(0, $transport->inFlight());
        $this->assertSame(
            [self::GAVE_UP . 'ValueError: curl_setopt_array(): cURL option must not contain any null bytes'],
            $this->logger->messages(),
        );
    }

    #[Group('ac-07-13')]
    #[MaximumDuration(1000)]
    public function testAThrowingClockIsLoggedNotThrown(): void
    {
        $base = self::collector()->scenario([], ['status' => 202, 'delayMs' => 300, 'body' => '']);
        $clock = static fn(): float => throw new \RuntimeException('clock broke');
        $transport = new CurlTransport(new RetryPolicy([0.01]), clock: $clock);

        $transport->start($this->delivery($base));
        $transport->pump();
        $transport->waitForActivity(INF);
        $this->assertFalse($transport->drain(INF));

        $this->assertSame(1, $transport->inFlight());
        $line = self::GAVE_UP . 'RuntimeException: clock broke';
        // pump(), waitForActivity(), then drain(): its own pump() and its deadline check.
        $this->assertSame([$line, $line, $line, $line], $this->logger->messages());
    }

    #[Group('ac-03-4')]
    #[MaximumDuration(3000)]
    public function testRetriesFollowTheTypeScriptScheduleWithoutSleeping(): void
    {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $base = self::collector()->scenario([], ['status' => 503, 'body' => '']);
        $transport = new CurlTransport(clock: $clock);
        $requests = static fn(): int => count(self::collector()->requests($base));

        $transport->start($this->delivery($base));
        $this->pumpUntil($transport, fn(): bool => $this->failures() === 1);
        $this->assertSame(1, $requests());
        $this->assertSame(1, $transport->inFlight());

        foreach ([[100.999, 101.0, 2], [102.999, 103.0, 3], [106.999, 107.0, 4]] as [$early, $due, $attempt]) {
            $now = $early;
            $this->pumpFor($transport, 0.05);
            $this->assertSame($attempt - 1, $requests(), 'retry ' . $attempt . ' started early');
            $this->assertSame($attempt - 1, $this->failures());

            $now = $due;
            $this->pumpUntil($transport, fn(): bool => $this->failures() === $attempt);
            $this->assertSame($attempt, $requests());
        }

        $failed = self::FAILED . 'HTTP 503]';
        $this->assertSame([$failed, $failed, $failed, $failed, self::GAVE_UP . 'HTTP 503'], $this->logger->messages());
        $this->assertSame(0, $transport->inFlight());
    }

    /** B03 (D15): the exporters run again before every API attempt. */
    #[Group('ac-03-4')]
    #[MaximumDuration(2000)]
    public function testBeforeAttemptRunsOnceBeforeEveryRequest(): void
    {
        $collector = self::collector();
        $base = $collector->scenario([], ['status' => 503, 'body' => '']);
        $seen = new \ArrayObject();
        $beforeAttempt = static function () use ($seen, $collector, $base): void {
            $seen[] = count($collector->requests($base));
        };
        $transport = $this->fast();

        $transport->start($this->delivery($base, $beforeAttempt));
        $this->assertSame([0], $seen->getArrayCopy());
        $this->assertTrue($transport->drain($this->deadline()));

        // The request count when each attempt was about to start: once before every request.
        $this->assertSame([0, 1, 2, 3], $seen->getArrayCopy());
        $this->assertCount(4, $collector->requests($base));
    }

    #[MaximumDuration(1000)]
    public function testAThrowingBeforeAttemptDoesNotStopTheDelivery(): void
    {
        $base = self::collector()->scenario();
        $beforeAttempt = static fn(): never => throw new \RuntimeException('exporter failed');

        $this->assertTrue($this->fast()->send($this->delivery($base, $beforeAttempt), $this->deadline()));

        $this->assertCount(1, self::collector()->requests($base));
        $this->assertSame([self::SUCCESS], $this->logger->messages());
    }

    #[MaximumDuration(3000)]
    public function testAtMostFiveTransfersRunAtOnce(): void
    {
        $base = self::collector()->scenario([], ['status' => 202, 'delayMs' => 300, 'body' => '{"status":"accepted"}']);
        $transport = $this->fast();
        $started = new \ArrayObject();
        $beforeAttempt = static function () use ($started): void {
            $started[] = true;
        };

        for ($i = 0; $i < 8; ++$i) {
            $transport->start($this->delivery($base, $beforeAttempt, 'evt_' . $i));
        }
        // No transfer can finish during the starts (300 ms): five started, three wait for a slot.
        $this->assertCount(5, $started);
        $this->assertSame(8, $transport->inFlight());
        $cpu = $this->cpuSeconds();
        $this->assertTrue($transport->drain($this->deadline()));

        $this->assertLessThan(0.15, $this->cpuSeconds() - $cpu, 'drain() spun while all slots were busy');
        $this->assertCount(8, $started);
        $requests = self::collector()->requests($base);
        $this->assertCount(8, $requests);
        $this->assertLessThanOrEqual(CurlTransport::MAX_CONCURRENT, MockCollector::maxConcurrency($requests));
        $this->assertLessThanOrEqual(5, MockCollector::maxConcurrency($requests));
        $this->assertGreaterThanOrEqual(2, MockCollector::maxConcurrency($requests));
        $messages = $this->logger->messages();
        sort($messages);
        $expected = array_map(
            static fn(int $i): string => 'Successfully sent event evt_' . $i . ' | mcp:tools/call | session ses_s | proj_p | 12 ms | anonymous',
            range(0, 7),
        );
        $this->assertSame($expected, $messages);
    }

    /**
     * Fix round 1: a transfer that finished while a slow logger held the loop is collected at once, not after a
     * 1 s wait on the socket of a slower transfer.
     */
    #[MaximumDuration(2000)]
    public function testAFinishedTransferIsCollectedWithoutWaitingForOthers(): void
    {
        $collector = self::collector();
        $slow = $collector->scenario([], ['status' => 202, 'delayMs' => 1200, 'body' => '']);
        $fast = $collector->scenario([], ['status' => 202, 'delayMs' => 100, 'body' => '']);
        $failing = $collector->scenario([], ['status' => 503, 'body' => '']);
        $logger = new class extends AbstractLogger {
            /** @var list<array{float, string}> */
            public array $lines = [];

            /** @param mixed[] $context */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = [hrtime(true) / 1e9, (string) $message];
                if (str_starts_with((string) $message, 'Failed to send event evt_b')) {
                    usleep(300_000); // a slow customer PSR logger; evt_a's response arrives meanwhile
                }
            }
        };
        Log::setLogger($logger);
        $transport = new CurlTransport(new RetryPolicy([10.0]));
        $transport->start(new Delivery(ApiBaseUrl::endpoint($slow), '{}', 'evt_c', 'slow'));
        $transport->start(new Delivery(ApiBaseUrl::endpoint($fast), '{}', 'evt_a', 'fast'));
        $transport->start(new Delivery(ApiBaseUrl::endpoint($failing), '{}', 'evt_b', 'failing'));

        $this->assertFalse($transport->drain($transport->now() + 1.0)); // evt_c runs 1.2 s, evt_b retries in 10 s

        $failed = 'Failed to send event evt_b, retrying... [Error: HTTP 503]';
        $sent = 'Successfully sent event evt_a | fast';
        $times = array_column($logger->lines, 0, 1); // message => when it was logged
        $this->assertSame([$failed, $sent], array_keys($times));
        $this->assertLessThan(0.7, ($times[$sent] ?? INF) - ($times[$failed] ?? 0.0));
    }

    #[MaximumDuration(2000)]
    public function testWaitForActivityReturnsAtOnceWhenAWaitingAttemptCanStart(): void
    {
        $collector = self::collector();
        $fast = $collector->scenario([], ['status' => 202, 'delayMs' => 50, 'body' => '']);
        $slow = $collector->scenario([], ['status' => 202, 'delayMs' => 500, 'body' => '']);
        $started = new \ArrayObject();
        $beforeAttempt = static function () use ($started): void {
            $started[] = true;
        };
        $transport = $this->fast();
        $transport->start($this->delivery($fast, $beforeAttempt, 'evt_0'));
        for ($i = 1; $i <= 5; ++$i) {
            $transport->start($this->delivery($slow, $beforeAttempt, 'evt_' . $i));
        }
        $this->assertCount(5, $started);
        // pump() starts waiting attempts before it collects: evt_0's slot frees up after the launch.
        $this->pumpUntil($transport, fn(): bool => $this->logger->messages() !== []);
        $this->assertCount(5, $started);
        $begin = hrtime(true);

        $transport->waitForActivity(INF);

        $this->assertLessThan(0.2, (hrtime(true) - $begin) / 1e9);
        $transport->pump();
        $this->assertCount(6, $started);
        $this->assertTrue($transport->drain($this->deadline())); // leave no slow request behind in the collector
    }

    #[Group('ac-07-13')]
    #[MaximumDuration(1000)]
    public function testAFailedAddIsAFailedAttempt(): void
    {
        $base = self::collector()->scenario();
        $transport = new CurlTransport(
            new RetryPolicy([0.01, 0.02, 0.04]),
            multiStatus: static fn(string $function, int $code): int => $function === 'curl_multi_add_handle' ? CURLM_INTERNAL_ERROR : $code,
        );
        $begin = hrtime(true);

        $this->assertTrue($transport->send($this->delivery($base), $this->deadline()));

        $this->assertLessThan(1.0, (hrtime(true) - $begin) / 1e9);
        $failed = self::FAILED . 'cURL multi error 4: Internal error]';
        $this->assertSame([$failed, $failed, $failed, $failed, self::GAVE_UP . 'cURL multi error 4: Internal error'], $this->logger->messages());
        $this->assertSame(0, $transport->inFlight());
        $this->assertSame([], self::collector()->requests($base), 'the handle was taken out of the multi handle again');
    }

    #[MaximumDuration(1000)]
    public function testAnAddThatFailsOnceIsRetried(): void
    {
        $base = self::collector()->scenario();
        $adds = new \ArrayObject();
        $multiStatus = static function (string $function, int $code) use ($adds): int {
            if ($function !== 'curl_multi_add_handle') {
                return $code;
            }
            $adds[] = $code;

            return count($adds) === 1 ? CURLM_INTERNAL_ERROR : $code;
        };
        $transport = new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]), multiStatus: $multiStatus);

        $this->assertTrue($transport->send($this->delivery($base), $this->deadline()));

        $this->assertSame([self::FAILED . 'cURL multi error 4: Internal error]', self::SUCCESS], $this->logger->messages());
        $this->assertCount(1, self::collector()->requests($base));
    }

    #[Group('ac-07-13')]
    #[MaximumDuration(1000)]
    public function testAFailingExecFailsTheTransfersSoNothingWaitsForever(): void
    {
        $base = self::collector()->scenario();
        $transport = new CurlTransport(
            new RetryPolicy([0.01, 0.02, 0.04]),
            multiStatus: static fn(string $function, int $code): int => $function === 'curl_multi_exec' ? CURLM_INTERNAL_ERROR : $code,
        );
        $begin = hrtime(true);

        $this->assertTrue($transport->send($this->delivery($base), $this->deadline()));

        $this->assertLessThan(1.0, (hrtime(true) - $begin) / 1e9);
        $failed = self::FAILED . 'cURL multi error 4: Internal error]';
        $this->assertSame([$failed, $failed, $failed, $failed, self::GAVE_UP . 'cURL multi error 4: Internal error'], $this->logger->messages());
        $this->assertSame(0, $transport->inFlight());
    }

    #[MaximumDuration(1000)]
    public function testAnExecThatFailsOnceIsRetried(): void
    {
        $base = self::collector()->scenario();
        $execs = new \ArrayObject();
        $multiStatus = static function (string $function, int $code) use ($execs): int {
            if ($function !== 'curl_multi_exec') {
                return $code;
            }
            $execs[] = $code;

            return count($execs) === 1 ? CURLM_INTERNAL_ERROR : $code;
        };
        $transport = new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]), multiStatus: $multiStatus);

        $this->assertTrue($transport->send($this->delivery($base), $this->deadline()));

        $this->assertSame([self::FAILED . 'cURL multi error 4: Internal error]', self::SUCCESS], $this->logger->messages());
        $this->assertSame(0, $transport->inFlight());
    }

    #[MaximumDuration(5000)]
    public function testDrainStopsAtItsDeadline(): void
    {
        $base = self::collector()->scenario([], ['status' => 202, 'delayMs' => 2000, 'body' => '']);
        $transport = $this->fast();
        $transport->start($this->delivery($base));
        $started = hrtime(true);

        $this->assertFalse($transport->drain($transport->now() + 0.2));

        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual(0.19, $elapsed);
        $this->assertLessThan(0.6, $elapsed);
        $this->assertSame(1, $transport->inFlight());
        $this->assertSame([], $this->logger->messages());

        $this->assertTrue($transport->drain(INF));
        $this->assertSame(0, $transport->inFlight());
        $this->assertSame([self::SUCCESS], $this->logger->messages());
    }

    #[MaximumDuration(1000)]
    public function testDrainAtItsDeadlinePumpsOnceAndReturnsFalse(): void
    {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $base = self::collector()->scenario([], ['status' => 202, 'delayMs' => 300, 'body' => '']);
        $transport = new CurlTransport(new RetryPolicy([0.01]), clock: $clock);
        $transport->start($this->delivery($base));

        $this->assertFalse($transport->drain(100.0)); // the frozen clock is exactly at the deadline

        $this->assertSame(1, $transport->inFlight());
        $this->assertSame([], $this->logger->messages());
    }

    #[MaximumDuration(2000)]
    public function testDrainWaitsWithoutSpinning(): void
    {
        $base = self::collector()->scenario([], ['status' => 202, 'delayMs' => 300, 'body' => '']);
        $transport = $this->fast();
        $transport->start($this->delivery($base));
        $cpu = $this->cpuSeconds();
        $started = hrtime(true);

        $this->assertTrue($transport->drain($this->deadline()));

        $wall = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual(0.25, $wall);
        $this->assertLessThan(0.1, $this->cpuSeconds() - $cpu, sprintf('drain() used the CPU for a %.2f s wait', $wall));
        $this->assertSame([self::SUCCESS], $this->logger->messages());
    }

    #[MaximumDuration(1000)]
    public function testDueRetriesStartInDueOrder(): void
    {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $order = new \ArrayObject();
        $attempt = static fn(string $name): \Closure => static function () use ($order, $name): void {
            $order[] = $name;
        };
        $url = MockCollector::closedPortUrl();
        $transport = new CurlTransport(new RetryPolicy([2.0, 0.5]), clock: $clock);
        $failures = fn(): int => count(array_filter(
            $this->logger->messages(),
            static fn(string $message): bool => str_starts_with($message, 'Failed to send event '),
        ));

        $transport->start($this->delivery($url, $attempt('a'), 'evt_a'));   // fails at 100: retry due at 102
        $this->pumpUntil($transport, static fn(): bool => $failures() === 1);
        $now = 101.0;
        $transport->start($this->delivery($url, $attempt('b'), 'evt_b'));   // fails at 101: retry due at 103
        $this->pumpUntil($transport, static fn(): bool => $failures() === 2);
        $now = 102.0;                                                       // a's 2nd attempt fails: due at 102.5
        $this->pumpUntil($transport, static fn(): bool => $failures() === 3);
        $this->assertSame(['a', 'b', 'a'], $order->getArrayCopy());

        $now = 103.0; // both are due; a (102.5) was scheduled after b (103) but is due first
        $this->pumpUntil($transport, static fn(): bool => $failures() === 5);

        $this->assertSame(['a', 'b', 'a', 'a', 'b'], $order->getArrayCopy());
    }

    #[Group('review-focus-2')]
    #[MaximumDuration(1000)]
    public function testWarningsDuringAnAttemptDoNotReachTheHostHandler(): void
    {
        $base = self::collector()->scenario();
        $beforeAttempt = static function (): void {
            trigger_error('exporter warning', E_USER_WARNING);
        };
        error_clear_last();

        $this->assertTrue($this->fast()->send($this->delivery($base, $beforeAttempt), $this->deadline()));

        $this->assertNull(error_get_last());
        $this->assertSame([self::SUCCESS], $this->logger->messages());
    }

    #[MaximumDuration(1000)]
    public function testWaitForActivityReturnsWhenARetryIsDue(): void
    {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = new CurlTransport(new RetryPolicy([0.05]), clock: $clock);
        $transport->start($this->delivery(MockCollector::closedPortUrl()));
        $this->pumpUntil($transport, fn(): bool => $this->failures() === 1);
        $started = hrtime(true);

        $transport->waitForActivity(INF); // the clock is frozen: the retry is due in 0.05 s of real sleep

        $elapsed = (hrtime(true) - $started) / 1e9;
        $this->assertGreaterThanOrEqual(0.045, $elapsed);
        $this->assertLessThan(0.5, $elapsed);
        $this->assertSame(1, $this->failures(), 'waitForActivity() does no work');
    }

    #[MaximumDuration(1000)]
    public function testWaitForActivityReturnsAtOnceWhenARetryIsOverdue(): void
    {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $transport = new CurlTransport(new RetryPolicy([0.05]), clock: $clock);
        $transport->start($this->delivery(MockCollector::closedPortUrl()));
        $this->pumpUntil($transport, fn(): bool => $this->failures() === 1);
        $now = 101.0; // the retry was due at 100.05 and nobody pumped
        $started = hrtime(true);

        $transport->waitForActivity(INF);

        $this->assertLessThan(0.05, (hrtime(true) - $started) / 1e9);
        $this->assertSame(1, $this->failures());
        $this->assertCount(1, $this->logger->messages());
    }

    #[MaximumDuration(1000)]
    public function testWaitForActivityReturnsAtOnceWhenTheDeadlinePassed(): void
    {
        $base = self::collector()->scenario([], ['status' => 202, 'delayMs' => 300, 'body' => '']);
        $transport = $this->fast();
        $transport->start($this->delivery($base));
        $started = hrtime(true);

        $transport->waitForActivity($transport->now());
        $transport->waitForActivity($transport->now() - 1.0);

        $this->assertLessThan(0.05, (hrtime(true) - $started) / 1e9);
        $this->assertSame(1, $transport->inFlight());
    }

    public function testNowIsMonotonicSeconds(): void
    {
        $transport = new CurlTransport();

        $first = $transport->now();
        $second = $transport->now();

        $this->assertGreaterThanOrEqual($first, $second);
        $this->assertEqualsWithDelta(hrtime(true) / 1e9, $second, 1.0);
    }

    #[Group('review-focus-2')]
    #[MaximumDuration(2000)]
    public function testThrowingErrorHandlerSeesNoWarningAndIsRestored(): void
    {
        set_error_handler(static fn(int $no, string $str): never => throw new \ErrorException($str, 0, $no));

        try {
            $this->assertTrue($this->fast()->send($this->delivery(MockCollector::closedPortUrl()), $this->deadline()));

            $messages = $this->logger->messages();
            $this->assertCount(5, $messages);
            $this->assertStringStartsWith(self::FAILED . 'cURL error 7: ', $messages[0]);
            $this->assertStringStartsWith(self::GAVE_UP . 'cURL error 7: ', $messages[4]);

            try {
                trigger_error('x', E_USER_WARNING);
                self::fail('the test error handler is no longer installed');
            } catch (\ErrorException $exception) {
                $this->assertSame('x', $exception->getMessage());
            }
        } finally {
            restore_error_handler();
        }
    }

    private static function collector(): MockCollector
    {
        self::assertNotNull(self::$collector, 'the mock collector is not running');

        return self::$collector;
    }

    private function event(string $id = 'evt_x'): Event
    {
        return new Event(
            id: $id,
            sessionId: 'ses_s',
            projectId: 'proj_p',
            eventType: Constants::TOOLS_CALL_EVENT_TYPE,
            timestamp: new \DateTimeImmutable('2026-10-16T12:00:00.000Z'),
            duration: 12,
            resourceName: 'get_weather',
            parameters: ['note' => 'secret-payload'],
        );
    }

    /** @param (\Closure(): void)|null $beforeAttempt */
    private function delivery(string $baseUrl, ?\Closure $beforeAttempt = null, string $id = 'evt_x'): Delivery
    {
        $event = $this->event($id);

        return Delivery::forEvent($event, ApiBaseUrl::endpoint($baseUrl), WireEncoder::encode($event), $beforeAttempt);
    }

    private function fast(): CurlTransport
    {
        return new CurlTransport(new RetryPolicy([0.01, 0.02, 0.04]));
    }

    /** A deadline on the real clock of every transport without a fake clock. */
    private function deadline(): float
    {
        return hrtime(true) / 1e9 + self::DEADLINE;
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

    /** User and system CPU time of this process. */
    private function cpuSeconds(): float
    {
        $usage = getrusage();
        $this->assertIsArray($usage);
        $seconds = 0.0;
        foreach (['ru_utime.tv_sec' => 1.0, 'ru_utime.tv_usec' => 1e-6, 'ru_stime.tv_sec' => 1.0, 'ru_stime.tv_usec' => 1e-6] as $field => $unit) {
            $value = $usage[$field] ?? null;
            $this->assertIsInt($value);
            $seconds += $value * $unit;
        }

        return $seconds;
    }

    /** @phpstan-impure */
    private function failures(): int
    {
        return count(array_filter(
            $this->logger->messages(),
            static fn(string $message): bool => str_starts_with($message, self::FAILED),
        ));
    }

    /** @param \Closure(): bool $done */
    private function pumpUntil(Transport $transport, \Closure $done): void
    {
        $deadline = hrtime(true) + (int) (self::DEADLINE * 1e9);
        while (true) {
            $this->timedPump($transport);
            if ($done()) {
                return;
            }
            if (hrtime(true) >= $deadline) {
                self::fail('condition not reached within ' . self::DEADLINE . ' s; log: ' . implode(' / ', $this->logger->messages()));
            }
            usleep(1000);
        }
    }

    private function pumpFor(Transport $transport, float $seconds): void
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);
        do {
            $this->timedPump($transport);
            usleep(1000);
        } while (hrtime(true) < $deadline);
    }

    /** pump() never blocks or sleeps. */
    private function timedPump(Transport $transport): void
    {
        $started = hrtime(true);
        $transport->pump();
        $this->assertLessThan(0.05, (hrtime(true) - $started) / 1e9, 'pump() blocked');
    }
}
