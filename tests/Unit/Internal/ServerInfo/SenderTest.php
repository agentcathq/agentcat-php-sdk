<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\Sender;
use AgentCat\Internal\ServerInfo\ServerInfoBody;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;
use AgentCat\Tests\Support\FakeServerInfo;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingHttpChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SenderTest extends TestCase
{
    private const string URL = 'https://api.example.test/v1/public/server-info/';

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

    public function testOnePostWithTheHeadersOfAnEventAndATenSecondLimit(): void
    {
        $channel = new RecordingHttpChannel([new HttpResponse(202, 'Accepted', '{"status":"accepted"}')]);
        $body = $this->body();

        Sender::send($channel, self::URL, $body);
        $this->assertSame([], $this->logger->messages(), 'nothing is logged before the answer');
        $channel->pump();

        $this->assertCount(1, $channel->requests);
        $request = $channel->requests[0];
        $this->assertSame(self::URL, $request->url);
        $this->assertSame(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $request->headers);
        $this->assertSame($body->encode(), $request->body);
        $this->assertFalse($request->background);
        $this->assertEqualsWithDelta(10.0, $request->totalTimeoutSeconds, PHP_FLOAT_EPSILON);
        $this->assertNull($request->connectTimeoutSeconds);
        $this->assertSame([sprintf('Sent server info heartbeat | 3 tools | %d bytes', strlen($body->encode()))], $this->logger->messages());
    }

    public function testTheLineOfASentHeartbeatNamesTheListsThatFailed(): void
    {
        $channel = new RecordingHttpChannel();
        $body = new ServerInfoBody('proj_a', 'demo-server', '1.2.3', '', '', [ServerInfoSource::TOOLS => []], [ServerInfoSource::PROMPTS => 'RuntimeException: boom', ServerInfoSource::RESOURCES => 'it took more than 5 s']);

        Sender::send($channel, self::URL, $body);
        $channel->pump();

        $this->assertSame([sprintf('Sent server info heartbeat | 0 tools | %d bytes | without prompts (RuntimeException: boom), resources (it took more than 5 s)', strlen($body->encode()))], $this->logger->messages());
    }

    public function testV13After413TheBodyGoesOnceMoreWithoutTheSchemas(): void
    {
        $channel = new RecordingHttpChannel([new HttpResponse(413, 'Payload Too Large', '{"error":"too large"}'), new HttpResponse(202, 'Accepted', '')]);
        $body = $this->body();

        Sender::send($channel, self::URL, $body);
        $channel->pump();

        $this->assertCount(2, $channel->requests);
        $this->assertSame($body->encode(), $channel->requests[0]->body);
        $second = json_decode($channel->requests[1]->body, false, flags: JSON_THROW_ON_ERROR);
        $first = json_decode($channel->requests[0]->body, false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $first);
        $this->assertInstanceOf(\stdClass::class, $second);
        foreach (JsonAssert::list(JsonAssert::at($second, 'features', 'tools')) as $index => $tool) {
            $this->assertObjectNotHasProperty('inputSchema', JsonAssert::object($tool));
            $this->assertObjectNotHasProperty('outputSchema', JsonAssert::object($tool));
            $full = JsonAssert::at($first, 'features', 'tools', $index);
            $this->assertSame([JsonAssert::at($full, 'name'), JsonAssert::optional($full, 'title'), JsonAssert::at($full, 'description')], [JsonAssert::at($tool, 'name'), JsonAssert::optional($tool, 'title'), JsonAssert::at($tool, 'description')]);
        }
        $this->assertObjectHasProperty('outputSchema', JsonAssert::object(JsonAssert::at($first, 'features', 'tools', 1)));
        $this->assertSame([self::URL, self::URL], $channel->urls());
        $this->assertEqualsWithDelta(10.0, $channel->requests[1]->totalTimeoutSeconds, PHP_FLOAT_EPSILON);
        $this->assertSame([
            'Server info heartbeat was too large (HTTP 413); sending it once more without the tool schemas',
            sprintf('Sent server info heartbeat | 3 tools | %d bytes', strlen($channel->requests[1]->body)),
        ], $this->logger->messages());
    }

    public function testV13ASecond413EndsTheAttempt(): void
    {
        $channel = new RecordingHttpChannel(default: new HttpResponse(413, 'Payload Too Large', ''));

        Sender::send($channel, self::URL, $this->body());
        $channel->pump();
        $channel->pump();

        $this->assertCount(2, $channel->requests);
        $this->assertSame('Server info heartbeat failed: HTTP 413', $this->logger->messages()[1] ?? null);
        $this->assertCount(2, $this->logger->messages());
    }

    /** @return iterable<string, array{HttpResponse, string}> */
    public static function otherAnswers(): iterable
    {
        yield '500' => [new HttpResponse(500, 'Internal Server Error', 'the body of the error'), 'Server info heartbeat failed: HTTP 500'];
        yield '503' => [new HttpResponse(503, '', ''), 'Server info heartbeat failed: HTTP 503'];
        yield '400' => [new HttpResponse(400, 'Bad Request', '{"error":"nope"}'), 'Server info heartbeat failed: HTTP 400'];
        yield '302' => [new HttpResponse(302, 'Found', ''), 'Server info heartbeat failed: HTTP 302'];
        yield 'no answer' => [HttpResponse::failed('cURL error 28: Operation timed out'), 'Server info heartbeat failed: cURL error 28: Operation timed out'];
    }

    #[DataProvider('otherAnswers')]
    public function testV10AnyOtherAnswerIsLoggedOnceAndNothingIsSentAgain(HttpResponse $answer, string $line): void
    {
        $channel = new RecordingHttpChannel(default: $answer);

        Sender::send($channel, self::URL, $this->body());
        $channel->pump();
        $channel->pump();

        $this->assertCount(1, $channel->requests);
        $this->assertSame([$line], $this->logger->messages());
    }

    public function testAResendThatCannotBeQueuedIsLoggedAndStaysInside(): void
    {
        $channel = new class implements HttpChannel {
            public int $posts = 0;

            /** @var list<\Closure(HttpResponse): void> */
            private array $waiting = [];

            #[\Override]
            public function post(HttpRequest $request, \Closure $onDone): void
            {
                if (++$this->posts === 2) {
                    throw new \LogicException('the channel is gone');
                }
                $this->waiting[] = $onDone;
            }

            #[\Override]
            public function pump(): void
            {
                foreach ($this->waiting as $onDone) {
                    $onDone(new HttpResponse(413, '', ''));
                }
                $this->waiting = [];
            }

            #[\Override]
            public function pending(bool $includeBackground = true): int
            {
                return count($this->waiting);
            }

            #[\Override]
            public function waitForActivity(float $seconds): void {}
        };

        Sender::send($channel, self::URL, $this->body());
        $channel->pump();

        $this->assertSame(2, $channel->posts);
        $this->assertSame('Server info heartbeat failed: LogicException', $this->logger->messages()[1] ?? null);
    }

    public function testWithoutSchemasAToolThatIsNoObjectStaysAsItIs(): void
    {
        $body = new ServerInfoBody('proj_a', 'demo-server', null, '', '', [ServerInfoSource::TOOLS => ['odd', FakeServerInfo::beta()], ServerInfoSource::PROMPTS => []]);

        $small = $body->withoutSchemas();

        $this->assertSame('odd', $small->features[ServerInfoSource::TOOLS][0] ?? null);
        $this->assertSame(['name', 'title', 'description', 'annotations'], JsonAssert::keys($small->features[ServerInfoSource::TOOLS][1] ?? null));
        $this->assertSame([ServerInfoSource::TOOLS, ServerInfoSource::PROMPTS], array_keys($small->features));
        $this->assertSame([ServerInfoSource::TOOLS], array_keys((new ServerInfoBody('proj_a', 'demo-server', null, '', '', []))->withoutSchemas()->features), 'a body always has its tool list');
    }

    public function testABodyThatJsonCannotCarryIsNotSent(): void
    {
        $channel = new RecordingHttpChannel();

        try {
            Sender::send($channel, self::URL, new ServerInfoBody('proj_a', 'demo-server', null, '', '', [ServerInfoSource::TOOLS => [(object) ['name' => NAN]]]));
            self::fail('the encoding error reaches the caller');
        } catch (\JsonException) {
            $this->assertSame([], $channel->requests);
        }
    }

    private function body(): ServerInfoBody
    {
        return new ServerInfoBody('proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa', 'demo-server', '1.2.3', 'Demo Title', 'Use the demo tools.', [ServerInfoSource::TOOLS => [FakeServerInfo::tool('alpha'), FakeServerInfo::beta(), FakeServerInfo::tool('gamma')]]);
    }
}
