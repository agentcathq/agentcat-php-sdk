<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\LaravelMcp;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\ToolListInjector;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\LaravelMcp\Internal\Delegate;
use AgentCat\LaravelMcp\Internal\HandshakeClient;
use AgentCat\LaravelMcp\Internal\TrackedInitialize;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\EmptyServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelStdioHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\LaravelMcp\LineTransport;
use AgentCat\Tests\Support\LaravelMcp\ListingServer;
use AgentCat\Tests\Support\LaravelMcp\PagedServer;
use AgentCat\Tests\Support\LaravelMcp\RecordingMethod;
use AgentCat\Tests\Support\Thrown;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

final class ListToolsTest extends LaravelTestCase
{
    private const array LIST = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];

    private const array TOOLS = ['echo', 'structured', 'refuses', 'boom', 'own'];

    #[Group('ac-02-6')]
    public function testListedToolsGetTheInjectedParametersAndGetMoreToolsComesLast(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());

        $result = $harness->listTools();

        $this->assertSame([...self::TOOLS, GetMoreTools::NAME], $this->names($result));
        $schema = JsonAssert::at($result, 'tools', 0, 'inputSchema');
        $this->assertSame(['msg', 'session_id', 'context'], $this->keys(JsonAssert::at($schema, 'properties')));
        $this->assertSame(['msg', 'session_id', 'context'], JsonAssert::at($schema, 'required'));
        $this->assertSame(Constants::SESSION_ID_PARAM_PATTERN, JsonAssert::at($schema, 'properties', 'session_id', 'pattern'));
        $this->assertSame(GetMoreTools::descriptor()->description, JsonAssert::at($result, 'tools', 5, 'description'));
    }

    #[Group('ac-02-8')]
    public function testEveryPageGetsGetMoreToolsAndKeepsItsCursor(): void
    {
        $fixture = new LaravelTrackingFixture();
        $harness = new LaravelHarness(PagedServer::class, $fixture->tracker());
        $twin = (new LaravelHarness(PagedServer::class))->listTools();

        $first = $harness->listTools();
        $this->assertIsString($first->nextCursor);
        $second = $harness->listTools($first->nextCursor);

        $this->assertCount(16, $this->names($first)); // 15 + get_more_tools (D15, B10)
        $this->assertSame(GetMoreTools::NAME, $this->names($first)[15]);
        $this->assertSame($twin->nextCursor, $first->nextCursor);
        $this->assertSame(['t15', GetMoreTools::NAME], $this->names($second));
        $this->assertObjectNotHasProperty('nextCursor', $second);
    }

    #[Group('ac-02-11')]
    public function testAnEmptyToolListIsServedAsItIs(): void
    {
        $fixture = new LaravelTrackingFixture();
        $untracked = (new LaravelHarness(EmptyServer::class))->listTools();

        $tracked = (new LaravelHarness(EmptyServer::class, $fixture->tracker()))->listTools();

        $this->assertEquals($untracked, $tracked);
        $this->assertSame([], $tracked->tools);
        $this->assertContains(ToolListInjector::NO_TOOLS_WARNING, $fixture->lines());
    }

    #[Group('ac-02-15')]
    public function testAListingPublishesNoEventAndRunsNoHook(): void
    {
        $fixture = new LaravelTrackingFixture();
        $hooks = 0;
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker(new Options(identify: static function () use (&$hooks): null {
            ++$hooks;

            return null;
        })));

        $harness->listTools();

        $this->assertSame([], $fixture->events());
        $this->assertSame(0, $hooks);
    }

    public function testEachServerListsWithItsOwnOptions(): void
    {
        $fixture = new LaravelTrackingFixture();
        $plain = new LaravelStdioHarness(ConformanceServer::class, $fixture->tracker(new Options(enableReportMissing: false)));
        $full = new LaravelStdioHarness(ConformanceServer::class, $fixture->tracker());

        $tools = $this->names(JsonAssert::at($full->send(self::LIST), 'result'));
        $without = $this->names(JsonAssert::at($plain->send(self::LIST), 'result'));

        $this->assertSame([...self::TOOLS, GetMoreTools::NAME], $tools);
        $this->assertSame(self::TOOLS, $without);
    }

    #[Group('ac-02-9')]
    public function testACustomerListHandlerIsWrapped(): void
    {
        $this->answer(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, [
            'tools' => [['name' => 'x', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()]]],
            'nextCursor' => 'c',
            '_meta' => ['source' => 'customer'],
        ]));
        $twinAnswer = (new LaravelHarness(ListingServer::class))->request('tools/list');

        $answer = (new LaravelHarness(ListingServer::class, (new LaravelTrackingFixture())->tracker()))->request('tools/list');

        $this->assertSame(['jsonrpc', 'id', 'result'], $this->keys($answer));
        $this->assertSame(1, $answer->id);
        $twin = JsonAssert::at($twinAnswer, 'result');
        $result = JsonAssert::at($answer, 'result');
        $this->assertInstanceOf(\stdClass::class, $twin);
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(['x', GetMoreTools::NAME], $this->names($result));
        $this->assertSame(['session_id', 'context'], $this->keys(JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties')));
        $this->assertSame($this->keys($twin), $this->keys($result));
        $this->assertSame('c', $result->nextCursor);
        $this->assertEquals($twin->_meta, $result->_meta);
    }

    #[Group('ac-02-10')]
    public function testAThrowingListHandlerGivesTheSameErrorAsWithoutAgentCat(): void
    {
        $this->answer(static function (): never {
            throw new \RuntimeException('list failed');
        });
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(ListingServer::class))->request('tools/list');

        $answer = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->request('tools/list');

        $this->assertObjectHasProperty('error', $answer);
        $this->assertEquals($twin, $answer);
        $this->assertSame([], $fixture->lines());
    }

    /** Task 4 review M1: PHP's own `Error`, not an AgentCat exception, reaches laravel/mcp's `report()`. */
    public function testAListHandlerTheContainerCannotResolveToAMethodFailsLikeWithoutAgentCat(): void
    {
        $this->app?->bind(RecordingMethod::class, static fn(): \stdClass => new \stdClass());
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(ListingServer::class))->request('tools/list');

        $answer = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->request('tools/list');

        $this->assertEquals((object) ['code' => -32603, 'message' => 'Something went wrong while processing the request.'], JsonAssert::at($answer, 'error'));
        $this->assertEquals($twin, $answer);
        $this->assertSame([], $fixture->lines());
        $context = (new ListingServer(new LineTransport()))->createContext();
        $error = Thrown::by(\Error::class, static fn(): mixed => Delegate::handle(RecordingMethod::class, new JsonRpcRequest(1, 'tools/list', []), $context));
        $this->assertSame(\Error::class, $error::class);
        $this->assertSame('Call to undefined method stdClass::handle()', $error->getMessage());
    }

    /** Task 4 review M1: laravel/mcp calls `handle()` on whatever the container returns, so AgentCat does too. */
    public function testAListHandlerThatIsNoMethodButHasHandleIsCalledAndWrapped(): void
    {
        $this->app?->bind(RecordingMethod::class, static fn(): object => new class {
            public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
            {
                return JsonRpcResponse::result($request->id, ['tools' => [['name' => 'duck', 'inputSchema' => ['type' => 'object']]]]);
            }
        });
        $fixture = new LaravelTrackingFixture();

        $result = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->listTools();

        $this->assertSame(['duck', GetMoreTools::NAME], $this->names($result));
    }

    public function testAJsonRpcErrorFromTheListHandlerPassesUnchanged(): void
    {
        $this->answer(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::error($request->id, -32602, 'bad cursor'));
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(ListingServer::class))->request('tools/list');

        $answer = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->request('tools/list');

        $this->assertEquals((object) ['code' => -32602, 'message' => 'bad cursor'], JsonAssert::at($answer, 'error'));
        $this->assertEquals($twin, $answer);
        $this->assertSame([], $fixture->lines(), 'an error is not a listing without tools');
    }

    public function testAStreamedListingPassesUnchanged(): void
    {
        $this->answer(static fn(JsonRpcRequest $request): array => [
            JsonRpcResponse::notification('notifications/progress', ['progress' => 1]),
            JsonRpcResponse::result($request->id, ['tools' => [['name' => 'x', 'inputSchema' => ['type' => 'object']]]]),
        ]);
        $fixture = new LaravelTrackingFixture();
        $twin = new LaravelStdioHarness(ListingServer::class);
        $twin->send(self::LIST);
        $harness = new LaravelStdioHarness(ListingServer::class, $fixture->tracker());

        $harness->send(self::LIST);

        $this->assertCount(2, $harness->lines());
        $this->assertEquals($twin->lines(), $harness->lines());
        $this->assertSame([], $fixture->lines(), 'a stream is not a listing without tools');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function resultsWithoutAToolList(): iterable
    {
        yield 'no tools member' => [['items' => [['name' => 'x']]]];
        yield 'tools is an object' => [['tools' => ['x' => ['name' => 'x']]]];
        yield 'tools is a string' => [['tools' => 'x']];
    }

    /** @param array<string, mixed> $result */
    #[DataProvider('resultsWithoutAToolList')]
    public function testAnAnswerWithoutAToolListPassesUnchanged(array $result): void
    {
        $this->answer(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, $result));
        $fixture = new LaravelTrackingFixture();
        $twin = (new LaravelHarness(ListingServer::class))->request('tools/list');

        $answer = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->request('tools/list');

        $this->assertEquals($twin, $answer);
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $fixture->lines(), 'TS listWrap: `tools` is not an array');
    }

    public function testAFailedInjectionServesTheOriginalListing(): void
    {
        $tool = new class implements \JsonSerializable {
            private bool $served = false;

            /** @return array<string, mixed> */
            #[\Override]
            public function jsonSerialize(): array
            {
                if (!$this->served) {
                    $this->served = true;

                    throw new \RuntimeException('tree failed');
                }

                return ['name' => 'flaky', 'inputSchema' => ['type' => 'object']];
            }
        };
        $this->answer(static fn(JsonRpcRequest $request): JsonRpcResponse => JsonRpcResponse::result($request->id, ['tools' => [$tool]]));
        $fixture = new LaravelTrackingFixture();

        $result = (new LaravelHarness(ListingServer::class, $fixture->tracker()))->listTools();

        $this->assertSame(['flaky'], $this->names($result));
        $this->assertSame(['Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - tree failed'], $fixture->lines());
    }

    public function testAListingStartsTheDeliveryOfAWaitingEvent(): void
    {
        $fixture = new LaravelTrackingFixture();
        $fixture->transport->complete = false;
        $harness = new LaravelHarness(ConformanceServer::class, $fixture->tracker());
        $fixture->queue->add(new QueuedEvent(new Event(sessionId: 'ses_aaaaaaaaaaaaaaaaaaaaaaaaaaa', projectId: LaravelTrackingFixture::PROJECT, eventType: Constants::TOOLS_CALL_EVENT_TYPE), null, null, []));
        $this->assertSame(['queueLength' => 1, 'activeRequests' => 0], $fixture->queue->stats());

        $harness->listTools();

        $this->assertSame(['queueLength' => 0, 'activeRequests' => 1], $fixture->queue->stats());
        $this->assertCount(1, $fixture->transport->deliveries);
    }

    public function testALegacyInitializeKeepsTheLibrarysAnswer(): void
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'desktop', 'version' => '0.9']]];
        $untracked = (new LaravelStdioHarness(ConformanceServer::class))->send($initialize);

        $tracked = (new LaravelStdioHarness(ConformanceServer::class, (new LaravelTrackingFixture())->tracker()))->send($initialize);

        $this->assertEquals($untracked, $tracked);
        $this->assertSame('2025-06-18', JsonAssert::at($tracked, 'result', 'protocolVersion'));
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function clientInfos(): iterable
    {
        yield 'an object' => [['name' => 'desktop', 'version' => '0.9'], ['name' => 'desktop', 'version' => '0.9']];
        yield 'a string' => ['desktop', null];
        yield 'none' => [null, null];
    }

    #[DataProvider('clientInfos')]
    public function testInitializeRecordsTheClientOfTheHandshakeAndAnswersLikeTheLibrary(mixed $clientInfo, mixed $recorded): void
    {
        $handshake = new HandshakeClient();
        $request = new JsonRpcRequest(1, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => $clientInfo]);
        $context = (new ConformanceServer(new LineTransport()))->createContext();

        $answer = (new TrackedInitialize(Initialize::class, $handshake))->handle($request, $context);

        $this->assertEquals((new Initialize())->handle($request, $context), $answer);
        $this->assertSame($recorded, $handshake->clientInfo());
    }

    /**
     * Binds the `RecordingMethod` that `ListingServer` names for `tools/list`, with this answer.
     *
     * @param \Closure(JsonRpcRequest): (iterable<JsonRpcResponse>|JsonRpcResponse) $answer
     */
    private function answer(\Closure $answer): void
    {
        $this->app?->instance(RecordingMethod::class, new RecordingMethod(answer: $answer));
    }

    /**
     * The names of a listing's tools, in order.
     *
     * @return list<string>
     */
    private function names(mixed $result): array
    {
        $tools = JsonAssert::at($result, 'tools');
        $this->assertIsList($tools);

        return array_map(function (mixed $tool): string {
            $name = JsonAssert::at($tool, 'name');
            $this->assertIsString($name);

            return $name;
        }, $tools);
    }

    /** @return list<string> */
    private function keys(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }
}
