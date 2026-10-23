<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\Entry;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** How mcp/sdk's error answers become error events (D16); a JSON-RPC error is never decorated. */
abstract class CallErrorsTestCase extends TestCase
{
    /** Where the tests hand the server to AgentCat (D76). */
    abstract protected static function entry(): Entry;

    /**
     * Skips a test of what only a Builder tracked before build() does. After build(), the library's handlers answer
     * first: a `tools/call` or `tools/list` handler of the customer's, a call to an unknown tool or with invalid
     * arguments, and a `tools/list` request never reach AgentCat (D77).
     */
    private function beforeBuildOnly(string $why): void
    {
        if (static::entry()->afterBuild()) {
            self::markTestSkipped('Tracked after build(): ' . $why);
        }
    }

    private TrackingFixture $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture(static::entry());
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-02-23')]
    #[Group('ac-04-36')]
    #[Group('ac-05-E5')]
    public function testAToolCallExceptionIsAnErrorResultAndTheEventKeepsTheException(): void
    {
        $twin = HttpHarness::result($this->fixture->harness($this->builder())->callTool('fails', []));
        $harness = $this->tracked();

        $result = HttpHarness::result($harness->callTool('fails', ['session_id' => 'start']));

        $event = $this->fixture->event();
        $this->assertTrue(JsonAssert::at($result, 'isError'));
        $this->assertSame([$this->issued($event->sessionId), 'no luck'], $this->texts($result));
        $this->assertSameAsTheTwinAfterTheIssuedBlock($twin, $result);
        $this->assertTrue($event->isError);
        $this->assertSame(\Mcp\Exception\ToolCallException::class, JsonAssert::at($event->error, 'type'));
        $this->assertSame('no luck', JsonAssert::at($event->error, 'message'));
        $this->assertNotSame([], JsonAssert::at($event->error, 'frames'), 'the stashed exception wins over the result text');
    }

    #[Group('ac-01-80')]
    public function testAnErrorResultWithoutAnExceptionIsAnErrorEvent(): void
    {
        $twin = HttpHarness::result($this->fixture->harness($this->builder())->callTool('refuses', []));
        $harness = $this->tracked();

        $result = HttpHarness::result($harness->callTool('refuses', ['session_id' => 'start']));

        $event = $this->fixture->event();
        $this->assertSame([$this->issued($event->sessionId), 'not allowed'], $this->texts($result));
        $this->assertSameAsTheTwinAfterTheIssuedBlock($twin, $result);
        $this->assertTrue($event->isError);
        $this->assertSame('{"message":"not allowed","platform":"php"}', $this->json($event->error), 'from the result text: nothing was thrown');
        $this->assertSame($this->json($twin), $this->json($event->response));
    }

    #[Group('ac-02-24')]
    #[Group('ac-02-25')]
    #[Group('ac-05-E5')]
    #[Group('ac-01-80')]
    #[Group('ac-04-44')]
    public function testAnUncaughtToolExceptionGivesTheLibrarysErrorAndAnEventWithTheException(): void
    {
        $twin = $this->fixture->harness($this->builder())->callTool('boom', []);
        $harness = $this->tracked();

        $answer = $harness->callTool('boom', ['session_id' => 'start']);

        $this->assertEquals((object) ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while executing tool'], $answer->error);
        $this->assertEquals($twin->error, $answer->error);
        $this->assertObjectNotHasProperty('result', $answer, 'no mint-back on a JSON-RPC error');
        $event = $this->fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('RuntimeException', JsonAssert::at($event->error, 'type'));
        $this->assertSame('tool boom', JsonAssert::at($event->error, 'message'));
        $this->assertSame('LogicException', JsonAssert::at($event->error, 'chained_errors', 0, 'type'));
        $this->assertNull($event->response);
    }

    #[Group('ac-02-26')]
    #[Group('ac-05-E6')]
    public function testAnUnknownToolGivesTheLibrarysErrorAndAnErrorEvent(): void
    {
        $this->beforeBuildOnly('a call to an unknown tool');
        $harness = $this->tracked();

        $answer = $harness->callTool('nope', ['session_id' => 'start']);

        $this->assertEquals((object) ['code' => Error::INVALID_PARAMS, 'message' => 'Tool not found: "nope".'], $answer->error);
        $event = $this->fixture->event();
        $this->assertSame('nope', $event->resourceName);
        $this->assertTrue($event->isError);
        $this->assertSame('{"message":"Tool not found: \"nope\".","platform":"php"}', $this->json($event->error));
    }

    #[Group('ac-05-E6')]
    public function testInvalidArgumentsGiveTheLibrarysErrorAndAnErrorEvent(): void
    {
        $twin = $this->fixture->harness($this->builder())->callTool('echo', ['msg' => 7]);
        $harness = $this->tracked();

        $answer = $harness->callTool('echo', ['msg' => 7, 'context' => 'why', 'session_id' => 'start']);

        $this->assertSame(Error::INVALID_PARAMS, JsonAssert::at($answer, 'error', 'code'));
        $message = JsonAssert::at($answer, 'error', 'message');
        $this->assertIsString($message);
        $this->assertStringStartsWith("Invalid parameters for tool 'echo'", $message);
        $this->assertEquals($twin->error, $answer->error, 'the error the server gives without AgentCat');
        if (static::entry()->afterBuild()) {
            $this->assertSame(0, $this->fixture->queue->stats()['queueLength'], 'the library answers before any tool runs (D77)');

            return;
        }
        $event = $this->fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame($message, JsonAssert::at($event->error, 'message'));
    }

    #[Group('ac-05-E7')]
    #[Group('ac-01-80')]
    public function testASuccessfulCallHasNoError(): void
    {
        $harness = $this->tracked();

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $event = $this->fixture->event();
        $this->assertNull($event->isError);
        $this->assertNull($event->error);
    }

    #[Group('ac-02-29')]
    public function testACallAfterAFailedOneIsNormal(): void
    {
        $harness = $this->tracked();

        $harness->callTool('boom', ['session_id' => 'start']);
        $result = HttpHarness::result($harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']));

        $failed = $this->fixture->event(0, of: 2);
        $normal = $this->fixture->event(1, of: 2);
        $this->assertTrue($failed->isError);
        $this->assertSame([$this->issued($normal->sessionId), 'echo: hi'], $this->texts($result));
        $this->assertNull($normal->isError);
        $this->assertNull($normal->error);
        $this->assertSame('minted', $normal->tags[Constants::TAG_SESSION_SOURCE] ?? null);
    }

    /** A tracked server with `echo`, `fails` and `boom` that has listed its tools. */
    private function tracked(): HttpHarness
    {
        $builder = $this->builder();
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        return $harness;
    }

    /** The customer's PSR-3 logger gets the library's one error line for a failing tool, with the tool's exception. */
    public function testTheBuildersLoggerGetsTheToolsExceptionOnce(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: mixed[]}> */
            public array $records = [];

            /** @param mixed[] $context */
            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $builder = $this->builder()->setLogger($logger);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $harness->callTool('boom', ['session_id' => 'start']);

        $errors = array_values(array_filter($logger->records, static fn(array $record): bool => $record['level'] === LogLevel::ERROR));
        $this->assertSame(['Unhandled error during tool execution'], array_column($errors, 'message'));
        $exception = $errors[0]['context']['exception'] ?? null;
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('tool boom', $exception->getMessage());
    }

    private function builder(): Builder
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        Tools::fails($builder);
        Tools::boom($builder);
        Tools::refuses($builder);

        return $builder;
    }

    private function issued(string $sessionId): string
    {
        return implode("\n", [Constants::MINT_BACK_HEADER_ISSUED, 'session_id: ' . $sessionId, Constants::MINT_BACK_ISSUED_BODY]);
    }

    /** The tracked answer is the untracked one with the issued block first. */
    private function assertSameAsTheTwinAfterTheIssuedBlock(\stdClass $twin, \stdClass $result): void
    {
        $content = $result->content ?? null;
        $this->assertIsArray($content);
        $this->assertSame($this->json($twin->content ?? null), $this->json(array_slice($content, 1)));
        $this->assertSame($twin->isError ?? null, $result->isError ?? null);
    }

    /** @return list<mixed> */
    private function texts(\stdClass $result): array
    {
        $content = $result->content ?? null;
        $this->assertIsList($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), $content);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
