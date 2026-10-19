<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\FastCgiClient;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;

/**
 * PHP-FPM, talked to over FastCGI the way nginx does: the client has its answer when FPM ends the request
 * (`FCGI_END_REQUEST`), and only then does AgentCat's shutdown function run the hooks and deliver (D47, plan E1 header
 * conflicts 13 and 14). Each upper bound sits about 1 s above the measured time and about 1 s below the time the step it
 * guards would add (a 3 s hook or a 3 s collector), ruling P16.
 */
#[Group('runtime')]
final class FpmTest extends RuntimeTestCase
{
    /** How long the request may take when nothing of AgentCat's runs before the answer; failure modes take 3 s or more. */
    private const float ANSWER_SECONDS = 2.0;

    /** The identify hook of `http-server.php` sleeps 3 s; a step after it starts at least this long after the answer. */
    private const float AFTER_THE_HOOK_SECONDS = 2.0;

    /** How long a test waits for the collector: the 3 s hook or the 3 s collector, then the delivery. */
    private const float COLLECTOR_DEADLINE_SECONDS = 10.0;

    private const string HTTP_SERVER = '/tests/E2E/Fixtures/http-server.php';

    #[Group('ac-03-1')]
    #[Group('ac-02-48')]
    #[MaximumDuration(30000)]
    public function testTheResponseReachesTheClientBeforeTheHooksAndTheDelivery(): void
    {
        $scenario = self::$collector->scenario([['status' => 202, 'delayMs' => 2000]]);
        $fpm = $this->fpm();
        $started = microtime(true);

        $answer = $fpm->request(self::repo() . self::HTTP_SERVER, 'POST', '/mcp', $this->headers('echo', [
            'X-E2E-Collector' => $scenario,
            'X-E2E-Identify-Sleep' => '1', // the identify hook sleeps 3 s, then writes the marker
        ]), $this->modernCall('echo', ['msg' => 'hi', 'session_id' => 'start']));

        $this->assertSame(200, $answer['status'], $answer['body']);
        $this->assertSame('echo: hi', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text'));
        $this->assertLessThan(self::ANSWER_SECONDS, $answer['endedAt'] - $started, 'FPM held the request for the hooks or the delivery');
        $request = $this->requestAt($scenario, 0);
        $this->assertSame('e2e-user', JsonAssert::at($this->event($request), 'identify_actor_given_id'));
        $this->assertGreaterThan($answer['endedAt'] + self::AFTER_THE_HOOK_SECONDS, $this->start($request), 'the delivery started after the hook, which ran after the answer');
        $wokeAt = file_get_contents($this->out() . '/identify'); // written in the container when the hook woke up
        $this->assertIsString($wokeAt);
        $this->assertIsNumeric($wokeAt);
        $this->assertGreaterThan($answer['endedAt'] + self::AFTER_THE_HOOK_SECONDS, (float) $wokeAt, 'identify woke up after the answer');
    }

    /**
     * Review Focus 5: AgentCat writes and closes the native session before its drain, so a second request of the same
     * session gets the lock while the first one still waits 3 s for the AgentCat API. Without that, PHP would write the
     * session after the shutdown functions, after the drain.
     */
    #[MaximumDuration(30000)]
    public function testTheSessionLockIsReleasedBeforeTheDrain(): void
    {
        $scenario = self::$collector->scenario([], ['status' => 202, 'delayMs' => 3000]);
        $fpm = $this->fpm();
        $script = self::repo() . '/tests/Runtime/Fixtures/fpm-session.php';
        $sentAt = microtime(true);

        $first = $fpm->request($script, 'POST', '/mcp', $this->headers('echo', ['X-E2E-Collector' => $scenario]), $this->modernCall('echo', ['msg' => 'one', 'session_id' => 'start']));

        $this->assertSame(200, $first['status'], $first['body']);
        $this->assertLessThan(self::ANSWER_SECONDS, $first['endedAt'] - $sentAt, 'FPM held the first request for the delivery');
        $cookie = $first['headers']['set-cookie'] ?? '';
        $this->assertStringStartsWith('PHPSESSID=', $cookie);
        $this->assertSame('1', $first['headers']['x-e2e-session-count'] ?? null);
        $started = microtime(true);

        $pair = strstr($cookie, ';', true); // PHPSESSID=<id>, without the attributes
        $second = $fpm->request($script, 'POST', '/mcp', $this->headers('echo', ['X-E2E-Collector' => $scenario, 'Cookie' => $pair === false ? $cookie : $pair]), $this->modernCall('echo', ['msg' => 'two', 'session_id' => 'start']));

        $this->assertSame(200, $second['status'], $second['body']);
        $this->assertSame('echo: two', JsonAssert::at($this->answerResult($second), 'content', 1, 'text'));
        $this->assertSame('2', $second['headers']['x-e2e-session-count'] ?? null, 'the second request read the session that the first one wrote');
        $this->assertLessThan(self::ANSWER_SECONDS, $second['endedAt'] - $started, 'the second request waited for the first one\'s session lock');
        $this->requestAt($scenario, 1); // the second request's event
        $this->assertGreaterThan($second['endedAt'] + 1.0, $this->end($this->requestAt($scenario, 0)), 'the first request\'s drain still waited for the collector when the second one had its answer');
    }

    /**
     * D47: under PHP-FPM a request posts its diagnostics once, at its end, after the answer. The 3 s hook keeps the drain
     * longer than the 2 s window of long-running servers. The posts are counted after the event deliveries of this
     * request and of one more request, which the pool's one worker serves only after the first request's script ended,
     * and whose post the test waits for too: each post carries all the lines of one request.
     */
    #[Group('ac-06-62')]
    #[MaximumDuration(30000)]
    public function testDiagnosticsArePostedOncePerRequestAfterTheResponse(): void
    {
        $events = self::$collector->scenario();
        $diagnostics = self::$collector->scenario([], ['status' => 200, 'delayMs' => 500]);
        $fpm = $this->fpm(workers: 1); // the one more request runs only once the first one's script, its drain included, ended
        $headers = ['X-E2E-Collector' => $events, 'X-E2E-Diagnostics' => $diagnostics];
        $started = microtime(true);

        $answer = $fpm->request(self::repo() . self::HTTP_SERVER, 'POST', '/mcp', $this->headers('echo', [...$headers, 'X-E2E-Identify-Sleep' => '1']), $this->modernCall('echo', ['msg' => 'one', 'session_id' => 'start']));

        $this->assertSame(200, $answer['status'], $answer['body']);
        $this->assertLessThan(self::ANSWER_SECONDS, $answer['endedAt'] - $started, 'FPM held the request for the hook, the delivery or the diagnostics');
        $first = $this->eventId($this->requestAt($events, 0));
        $more = $fpm->request(self::repo() . self::HTTP_SERVER, 'POST', '/mcp', $this->headers('echo', $headers), $this->modernCall('echo', ['msg' => 'two', 'session_id' => 'start']));
        $this->assertSame(200, $more['status'], $more['body']);
        $second = $this->eventId($this->requestAt($events, 1));
        $posts = $this->waitForPostWith($diagnostics, 'Successfully sent event ' . $second);

        $this->assertCount(2, $posts, 'one diagnostics POST per request');
        foreach ($posts as $post) {
            $this->assertSame('/v1/logs', $post->path ?? null);
            $this->assertSame(1, substr_count($this->body($post), 'AgentCat setup started'), 'each POST carries all the lines of one request');
        }
        $own = array_values(array_filter($posts, fn(\stdClass $post): bool => str_contains($this->body($post), 'Successfully sent event ' . $first)));
        $this->assertCount(1, $own, 'one POST carries the delivery line of the first request');
        $this->assertGreaterThan($answer['endedAt'] + self::AFTER_THE_HOOK_SECONDS, $this->start($own[0]), 'the first request posted its diagnostics after the hook, which ran after the answer');
    }

    /**
     * Plan E1 header conflict 6: a streamed laravel/mcp answer (two progress notifications 50 ms apart, then the result)
     * reaches the client as server-sent events, and its event comes when the stream ends, with a `duration` that covers
     * the stream.
     */
    #[Group('ac-02-54')]
    #[MaximumDuration(40000)]
    public function testALaravelStreamIsRecordedAfterTheStreamEnds(): void
    {
        $scenario = self::$collector->scenario();
        $fpm = $this->fpm();

        $answer = $fpm->request(self::repo() . '/tests/Runtime/Fixtures/laravel-front.php', 'POST', '/mcp/e2e-stream', $this->headers('stream', ['X-E2E-Collector' => $scenario]), $this->modernCall('stream', ['session_id' => 'start']));

        $this->assertSame(200, $answer['status'], $answer['body']);
        $this->assertStringStartsWith('text/event-stream', $answer['headers']['content-type'] ?? '');
        $this->assertSame(3, substr_count($answer['body'], 'data: '), 'two notifications, then the result');
        $this->assertSame('done', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text'));
        $event = $this->event($this->requestAt($scenario, 0));
        $this->assertSame('stream', JsonAssert::at($event, 'resource_name'));
        $this->assertGreaterThanOrEqual(100, JsonAssert::at($event, 'duration'), 'the duration covers both 50 ms waits of the stream');
    }

    /**
     * Header conflict 7 (D58): under PHP-FPM `Response::send()` finishes the request, `kernel.terminate` makes one
     * non-blocking pass, and AgentCat's shutdown function delivers, so the client has its answer before the collector
     * answers 3 s later. An untimed first request compiles the container.
     */
    #[MaximumDuration(40000)]
    public function testSymfonyAnswersBeforeTheShutdownDelivery(): void
    {
        $scenario = self::$collector->scenario([['status' => 202], ['status' => 202, 'delayMs' => 3000]]);
        $fpm = $this->fpm($scenario);
        $script = self::repo() . '/tests/Runtime/Fixtures/symfony-front.php';
        $warmUp = $fpm->request($script, 'POST', '/mcp/conformance', $this->headers('echo', []), $this->modernCall('echo', ['msg' => 'warm', 'session_id' => 'start']), 30.0);
        $this->assertSame(200, $warmUp['status'], $warmUp['body']);
        $this->requestAt($scenario, 0); // the warm-up's event takes the undelayed step
        $started = microtime(true);

        $answer = $fpm->request($script, 'POST', '/mcp/conformance', $this->headers('echo', []), $this->modernCall('echo', ['msg' => 'hi', 'session_id' => 'start']));

        $this->assertSame(200, $answer['status'], $answer['body']);
        $this->assertSame('echo: hi', JsonAssert::at($this->answerResult($answer), 'content', 1, 'text'));
        $this->assertLessThan(self::ANSWER_SECONDS, $answer['endedAt'] - $started, 'FPM held the request for the delivery');
        $delivery = $this->requestAt($scenario, 1);
        $event = $this->event($delivery);
        $this->assertSame(['conformance-server', 'echo'], [JsonAssert::at($event, 'server_name'), JsonAssert::at($event, 'resource_name')]);
        $this->assertGreaterThan($answer['endedAt'] + 1.0, $this->end($delivery), 'the collector answered the delivery after the client had its answer');
    }

    /**
     * PHP-FPM on 127.0.0.1 with `$workers` workers (two by default: one can drain while the other answers), which keep
     * the container's environment (`RuntimeTestCase::env()` with `$collector` as the AgentCat API) and get `HOME=/out`,
     * as FPM gives its workers `/var/www` otherwise.
     */
    private function fpm(?string $collector = null, int $workers = 2): FastCgiClient
    {
        $out = $this->out();
        $port = $this->freePort();
        $pool = $out . '/pool.conf';
        file_put_contents($pool, implode("\n", [
            '[www]',
            'listen = 127.0.0.1:' . $port,
            'clear_env = no',
            'env[HOME] = /out',
            'pm = static',
            'pm.max_children = ' . $workers,
            'catch_workers_output = yes',
            'decorate_workers_output = no',
            '',
        ]));
        DockerRuntime::start(Images::FPM, ['php-fpm', '-F'], $this->env($collector), [$pool => '/usr/local/etc/php-fpm.d/zzz-agentcat.conf', $out => '/out'], port: $port);

        return new FastCgiClient('127.0.0.1', $port);
    }

    /**
     * The headers of a 2026-07-28 `tools/call` of `$tool`, as a client sends them, plus `$extra`.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function headers(string $tool, array $extra): array
    {
        return ['Accept' => 'application/json, text/event-stream', ...HttpHarness::modernHeaders('tools/call', $tool), ...$extra];
    }

    /**
     * A 2026-07-28 `tools/call` (id 1) from `fpm-client`: no handshake, the client in `_meta`.
     *
     * @param array<string, string> $arguments
     */
    private function modernCall(string $tool, array $arguments): string
    {
        $params = HttpHarness::modernParams(['name' => $tool, 'arguments' => (object) $arguments], ['name' => 'fpm-client', 'version' => '1.0.0']);

        return json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params], JSON_THROW_ON_ERROR);
    }

    /** @param array{status: int, headers: array<string, string>, body: string, endedAt: float} $answer */
    private function answerResult(array $answer): \stdClass
    {
        return HttpHarness::result(HttpHarness::decode($answer['body'], $answer['headers']['content-type'] ?? '', 1));
    }

    /** Waits for request `$index` (from 0, in arrival order) of `$scenario`, and fails the test after the deadline. */
    private function requestAt(string $scenario, int $index): \stdClass
    {
        $request = $this->waitForRequests($scenario, $index + 1, self::COLLECTOR_DEADLINE_SECONDS)[$index] ?? null;
        $this->assertInstanceOf(\stdClass::class, $request);

        return $request;
    }

    /** The event that a request to the collector carried. */
    private function event(\stdClass $request): \stdClass
    {
        $event = Json::decode($this->body($request));
        $this->assertInstanceOf(\stdClass::class, $event);

        return $event;
    }

    private function eventId(\stdClass $request): string
    {
        $id = JsonAssert::at($this->event($request), 'id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * Waits until a request of `$scenario` carries `$line`, and fails the test after the deadline.
     *
     * @return list<\stdClass> every request of the scenario so far
     */
    private function waitForPostWith(string $scenario, string $line): array
    {
        $deadline = microtime(true) + self::COLLECTOR_DEADLINE_SECONDS;
        do {
            $posts = self::$collector->requests($scenario);
            foreach ($posts as $post) {
                if (str_contains($this->body($post), $line)) {
                    return $posts;
                }
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        self::fail(sprintf('No request of %s carried "%s" within %.1f s: %d requests', $scenario, $line, self::COLLECTOR_DEADLINE_SECONDS, count($posts)));
    }

    private function body(\stdClass $request): string
    {
        $body = $request->body ?? null;
        $this->assertIsString($body);

        return $body;
    }

    /** When the request reached the collector. */
    private function start(\stdClass $request): float
    {
        $start = $request->start ?? null;
        $this->assertIsFloat($start);

        return $start;
    }

    /** When the collector answered the request (after its delay). */
    private function end(\stdClass $request): float
    {
        $end = $request->end ?? null;
        $this->assertIsFloat($end);

        return $end;
    }
}
