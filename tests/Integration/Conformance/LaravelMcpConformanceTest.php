<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Conformance;

use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelConformanceHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelStdioHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\LaravelMcp\RequestEchoServer;
use AgentCat\Tests\Support\LaravelMcp\ToolCalls;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use PHPUnit\Framework\Attributes\Group;

/** The laravel/mcp leg of the adapter conformance suite, and where laravel/mcp differs from the other libraries. */
final class LaravelMcpConformanceTest extends LaravelTestCase
{
    use AdapterConformance;

    /** With `app.debug` true, laravel/mcp answers with the exception's own message; the event keeps the exception. */
    #[Group('ac-02-24')]
    #[Group('ac-05-E5')]
    public function testWithAppDebugTheWireIsTheLibrarysAndTheEventKeepsTheException(): void
    {
        config(['app.debug' => true]);
        $twin = LaravelConformanceHarness::untracked();
        $twin->listTools();
        $harness = LaravelConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertEquals($twin->callTool('boom', [])->result ?? null, $answer->result ?? null);
        $this->assertSame('tool boom', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $this->assertTrue(JsonAssert::at($answer, 'result', 'isError'));
        $event = $this->onlyEvent($harness);
        $this->assertTrue($event->isError);
        $this->assertSame(['RuntimeException', 'tool boom'], [JsonAssert::at($event->error, 'type'), JsonAssert::at($event->error, 'message')]);
        $this->assertSame('LogicException', JsonAssert::at($event->error, 'chained_errors', 0, 'type'));
    }

    /**
     * Library difference: a laravel/mcp tool validates its own arguments (`$request->validate()`), and the library
     * answers the `ValidationException` with an error result; the event keeps that exception (plan E1 header
     * conflict 5).
     */
    #[Group('ac-05-E6')]
    public function testAValidationErrorIsTheToolsExceptionAndAnErrorResult(): void
    {
        $twin = LaravelConformanceHarness::untracked();
        $twin->listTools();
        $harness = LaravelConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('echo', ['context' => 'why', 'session_id' => self::SESSION]);

        $this->assertEquals($twin->callTool('echo', [])->result ?? null, $answer->result ?? null);
        $this->assertTrue(JsonAssert::at($answer, 'result', 'isError'));
        $this->assertSame('The msg field is required.', JsonAssert::at($answer, 'result', 'content', 0, 'text'));
        $event = $this->onlyEvent($harness);
        $this->assertSame([ValidationException::class, 'The msg field is required.'], [JsonAssert::at($event->error, 'type'), JsonAssert::at($event->error, 'message')]);
    }

    /** laravel/mcp binds each call's `mcp.request` in the container: no call sees the arguments of the one before. */
    #[Group('ac-02-51')]
    #[Group('ac-05-E5')]
    public function testConsecutiveCallsSeeOnlyTheirOwnArgumentsThroughMcpRequest(): void
    {
        $fixture = new LaravelTrackingFixture();
        $stdio = new LaravelStdioHarness(RequestEchoServer::class, $fixture->tracker());

        $stdio->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'request-echo', 'arguments' => ['msg' => 'one', 'context' => 'first', 'session_id' => self::SESSION], '_meta' => LaravelHarness::meta([], null)]]);
        $stdio->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'request-echo', 'arguments' => ['msg' => 'two'], '_meta' => LaravelHarness::meta([], null)]]);

        $this->assertSame([
            ['request' => ['msg' => 'one'], 'container' => ['msg' => 'one']],
            ['request' => ['msg' => 'two'], 'container' => ['msg' => 'two']],
        ], ToolCalls::of('request-echo'));
        $this->assertFalse($this->app?->bound('mcp.request'), 'laravel/mcp forgets each call\'s request');
        $this->assertCount(2, $fixture->events());
    }

    /**
     * Library difference: laravel/mcp answers an uncaught tool exception with an error result, which the event keeps as
     * its response next to the exception (plan E1 header conflict 5); mcp/sdk answers with a JSON-RPC error.
     */
    #[Group('ac-02-24')]
    public function testAnUncaughtToolExceptionIsAnErrorResultThatTheEventKeeps(): void
    {
        $twin = LaravelConformanceHarness::untracked();
        $twin->listTools();
        $harness = LaravelConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertEquals($twin->callTool('boom', [])->result ?? null, $answer->result ?? null);
        $event = $this->onlyEvent($harness);
        $this->assertSame('RuntimeException', JsonAssert::at($event->error, 'type'));
        $this->assertEquals((object) ['content' => [(object) ['type' => 'text', 'text' => 'An internal server error occurred.']], 'isError' => true], $event->response);
    }

    /**
     * Library difference: laravel/mcp throws its `JsonRpcException` for an unknown tool through AgentCat's handler, so
     * the event keeps that exception; mcp/sdk answers with an error and throws nothing that AgentCat sees.
     */
    #[Group('ac-02-26')]
    #[Group('ac-05-E6')]
    public function testAnUnknownToolIsTheLibrarysJsonRpcExceptionWhichTheEventKeeps(): void
    {
        $harness = LaravelConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('nope', ['session_id' => self::SESSION]);

        $this->assertEquals((object) ['code' => -32602, 'message' => 'Tool [nope] not found.'], $answer->error ?? null);
        $event = $this->onlyEvent($harness);
        $this->assertSame([JsonRpcException::class, 'Tool [nope] not found.'], [JsonAssert::at($event->error, 'type'), JsonAssert::at($event->error, 'message')]);
    }

    /**
     * Library difference: laravel/mcp 1.x has no sessions, so `extra` has no transport session (plan E1 header conflict
     * 8); over HTTP the event has the server's own request (header conflict 9), whatever the SAPI.
     */
    #[Group('ac-02-43')]
    #[Group('ac-02-46')]
    public function testExtraHasNoTransportSessionAndTheServersOwnHttpRequest(): void
    {
        $harness = LaravelConformanceHarness::of();
        $harness->listTools();
        $fresh = LaravelConformanceHarness::of(fresh: true);

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $fresh->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $this->assertSame(['requestId'], array_keys((array) JsonAssert::at($event->parameters, 'extra')));
        $http = $this->onlyEvent($fresh);
        $extra = JsonAssert::at($http->parameters, 'extra');
        $this->assertSame(['requestId', 'requestInfo'], array_keys((array) $extra));
        $this->assertSame('http://localhost/mcp/conformance', JsonAssert::at($extra, 'requestInfo', 'url'));
    }

    #[\Override]
    protected function conformance(Options $options = new Options(), bool $fresh = false): ConformanceHarness
    {
        return LaravelConformanceHarness::of($options, $fresh);
    }

    #[\Override]
    protected function reusedObjectId(): ConformanceHarness
    {
        return LaravelConformanceHarness::reusedObjectId();
    }

    #[\Override]
    protected function fullPage(): ConformanceHarness
    {
        return LaravelConformanceHarness::fullPage();
    }

    /** One HTTP request to a tracked server, built from the server variables like `Request::capture()` does. */
    #[\Override]
    protected function requestInfoOf(array $server): \stdClass
    {
        $fixture = new LaravelTrackingFixture();
        (new LaravelHarness(ConformanceServer::class, $fixture->tracker()))->requestFromServer($server, 'tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => self::SESSION]]);

        $info = JsonAssert::at($fixture->event()->parameters, 'extra', 'requestInfo');
        $this->assertInstanceOf(\stdClass::class, $info);

        return $info;
    }
}
