<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Conformance;

use AgentCat\Internal\Engine\HttpContext;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\RequestReader;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\McpSdkConformanceHarness;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The mcp/sdk leg of the adapter conformance suite, and where mcp/sdk differs from the other libraries. */
final class McpSdkConformanceTest extends TestCase
{
    use AdapterConformance;

    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
        Log::reset();
    }

    /**
     * Library difference: mcp/sdk checks the arguments against the tool's input schema before the tool runs and
     * answers a JSON-RPC error; the event keeps that error's message.
     */
    #[Group('ac-05-E6')]
    public function testAValidationErrorIsTheLibrarysJsonRpcError(): void
    {
        $twin = McpSdkConformanceHarness::untracked();
        $twin->listTools();
        $harness = McpSdkConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('echo', ['context' => 'why', 'session_id' => self::SESSION]);

        $this->assertEquals($twin->callTool('echo', [])->error ?? null, $answer->error ?? null);
        $this->assertSame(Error::INVALID_PARAMS, JsonAssert::at($answer, 'error', 'code'));
        $message = JsonAssert::at($answer, 'error', 'message');
        $this->assertIsString($message);
        $this->assertStringStartsWith("Invalid parameters for tool 'echo'", $message);
        $event = $this->onlyEvent($harness);
        $this->assertSame($message, JsonAssert::at($event->error, 'message'));
        $error = $event->error;
        $this->assertInstanceOf(\stdClass::class, $error);
        $this->assertObjectNotHasProperty('type', $error, 'nothing was thrown');
    }

    /**
     * Library difference: mcp/sdk answers an uncaught tool exception with a JSON-RPC error, so the event has the
     * exception and no response; laravel/mcp answers with an error result.
     */
    #[Group('ac-02-25')]
    public function testAnUncaughtToolExceptionIsTheLibrarysJsonRpcErrorAndAnEventWithoutResponse(): void
    {
        $twin = McpSdkConformanceHarness::untracked();
        $twin->listTools();
        $harness = McpSdkConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertEquals($twin->callTool('boom', [])->error ?? null, $answer->error ?? null);
        $this->assertEquals((object) ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while executing tool'], $answer->error ?? null);
        $event = $this->onlyEvent($harness);
        $this->assertSame('RuntimeException', JsonAssert::at($event->error, 'type'));
        $this->assertNull($event->response);
    }

    /**
     * Library difference: mcp/sdk answers an unknown tool with its own error message and throws nothing that AgentCat
     * sees, so the event has the message only; laravel/mcp throws its `JsonRpcException` through AgentCat's handler.
     */
    #[Group('ac-02-26')]
    #[Group('ac-05-E6')]
    public function testAnUnknownToolIsTheLibrarysErrorAndAnEventWithItsMessageOnly(): void
    {
        $harness = McpSdkConformanceHarness::of();
        $harness->listTools();

        $answer = $harness->callTool('nope', ['session_id' => self::SESSION]);

        $this->assertEquals((object) ['code' => Error::INVALID_PARAMS, 'message' => 'Tool not found: "nope".'], $answer->error ?? null);
        $event = $this->onlyEvent($harness);
        $this->assertEquals((object) ['message' => 'Tool not found: "nope".', 'platform' => 'php'], $event->error);
    }

    /**
     * Library difference: a handshake-era call has mcp/sdk's transport session in `extra`, never as the event's session
     * (ac-02-46); laravel/mcp 1.x has no sessions. The adapter reads the HTTP request from the SAPI (`$_SERVER`), which
     * a request in this process does not set: no `requestInfo`.
     */
    #[Group('ac-02-46')]
    public function testExtraHasTheTransportSessionOfTheHandshakeAndNoRequestInfoInProcess(): void
    {
        $harness = McpSdkConformanceHarness::of();
        $harness->listTools();
        $fresh = McpSdkConformanceHarness::of(fresh: true);

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $fresh->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $extra = JsonAssert::at($event->parameters, 'extra');
        $this->assertSame(['sessionId', 'requestId'], array_keys((array) $extra));
        $this->assertIsString(JsonAssert::at($extra, 'sessionId'));
        $this->assertNotSame(self::SESSION, JsonAssert::at($extra, 'sessionId'));
        $modern = $this->onlyEvent($fresh);
        $this->assertSame(['requestId', '_meta'], array_keys((array) JsonAssert::at($modern->parameters, 'extra')));
    }

    /** mcp/sdk rejects a `tools/call` without a name before any handler: nothing is recorded, as on laravel/mcp (D15). */
    public function testACallWithoutAToolNameIsTheLibrarysErrorAndNoEvent(): void
    {
        $fixture = new TrackingFixture();
        $untracked = TrackingFixture::builder();
        Tools::echo($untracked);
        $twin = $fixture->harness($untracked)->request('tools/call', ['arguments' => ['msg' => 'hi']]);
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $fixture->track($builder);

        $answer = $fixture->harness($builder)->request('tools/call', ['arguments' => ['msg' => 'hi']]);

        $this->assertObjectHasProperty('error', $answer);
        $this->assertEquals($twin, $answer);
        $this->assertSame([], $fixture->events());
        $this->assertSame([], $fixture->lines());
    }

    #[\Override]
    protected function conformance(Options $options = new Options(), bool $fresh = false): ConformanceHarness
    {
        return McpSdkConformanceHarness::of($options, $fresh);
    }

    #[\Override]
    protected function reusedObjectId(): ConformanceHarness
    {
        return McpSdkConformanceHarness::reusedObjectId();
    }

    #[\Override]
    protected function fullPage(): ConformanceHarness
    {
        return McpSdkConformanceHarness::fullPage();
    }

    #[\Override]
    protected function paged(): ConformanceHarness
    {
        return McpSdkConformanceHarness::paged();
    }

    /** The adapter's reader with what `HttpContext::capture()` gives under PHP-FPM for these server variables. */
    #[\Override]
    protected function requestInfoOf(array $server): \stdClass
    {
        $reader = new RequestReader(static fn(): ?HttpContext => HttpContext::fromServer('fpm-fcgi', $server));
        $request = (new CallToolRequest('echo', ['msg' => 'hi', 'session_id' => self::SESSION]))->withId(1);

        $info = JsonAssert::at($reader->read($request, new Session(new InMemorySessionStore()))->extra, 'requestInfo');
        $this->assertInstanceOf(\stdClass::class, $info);

        return $info;
    }
}
