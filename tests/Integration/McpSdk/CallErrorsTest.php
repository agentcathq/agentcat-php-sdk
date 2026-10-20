<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** How mcp/sdk's error answers become error events (plan header conflict 3, D16). */
final class CallErrorsTest extends TestCase
{
    private TrackingFixture $fixture;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture();
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
        $this->assertObjectNotHasProperty('result', $answer, 'no mint-back on a JSON-RPC error (header conflict 3)');
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
        $harness = $this->tracked();

        $answer = $harness->callTool('echo', ['context' => 'why', 'session_id' => 'start']);

        $this->assertSame(Error::INVALID_PARAMS, JsonAssert::at($answer, 'error', 'code'));
        $message = JsonAssert::at($answer, 'error', 'message');
        $this->assertIsString($message);
        $this->assertStringStartsWith("Invalid parameters for tool 'echo'", $message);
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
