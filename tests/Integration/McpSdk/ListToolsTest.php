<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Event;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ToolListInjector;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Pipeline\QueuedEvent;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\RecordingTool;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ListToolsTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string CONTEXT_DESCRIPTION = 'A description of your goal and what kind of tool would help accomplish it.';

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private EventQueue $queue;

    private Publisher $publisher;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->queue = new EventQueue(transport: $this->transport);
        $this->publisher = new Publisher($this->queue);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-02-6')]
    #[Group('ac-02-39')]
    public function testEveryToolGetsTheHandlesAndTheContextAndGetMoreToolsComesLast(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($result));
        $schema = JsonAssert::at($result, 'tools', 0, 'inputSchema');
        $this->assertSame(['msg', 'session_id', 'context'], $this->keys(JsonAssert::at($schema, 'properties')));
        $this->assertSame(['msg', 'session_id', 'context'], JsonAssert::at($schema, 'required'));
        $this->assertSame(Constants::SESSION_ID_PARAM_PATTERN, JsonAssert::at($schema, 'properties', 'session_id', 'pattern'));
        $descriptor = GetMoreTools::descriptor();
        $this->assertSame($descriptor->description, JsonAssert::at($result, 'tools', 1, 'description'));
        $this->assertEquals($descriptor->annotations, JsonAssert::at($result, 'tools', 1, 'annotations'));
    }

    #[Group('ac-02-7')]
    public function testAgentTrackingAddsARequiredAgentId(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(enableAgentTracking: true));

        $result = $this->harness($builder)->listTools();

        $echo = JsonAssert::at($result, 'tools', 0, 'inputSchema');
        $this->assertSame(['msg', 'session_id', 'agent_id', 'context'], $this->keys(JsonAssert::at($echo, 'properties')));
        $this->assertSame(['msg', 'session_id', 'agent_id', 'context'], JsonAssert::at($echo, 'required'));
        $more = JsonAssert::at($result, 'tools', 1, 'inputSchema', 'properties');
        $this->assertSame(['context', 'session_id', 'agent_id'], $this->keys($more));
        $this->assertSame(self::CONTEXT_DESCRIPTION, JsonAssert::at($more, 'context', 'description'));
    }

    public function testTheModernEraGetsTheSameInjectedListing(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);
        $harness = $this->harness($builder);

        $handshake = $harness->listTools();
        $modern = $harness->listToolsModern();

        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($modern));
        $this->assertEquals($handshake->tools, $modern->tools);
        $this->assertSame('complete', $modern->resultType);
    }

    /** D66 (B10 fixed): every page is injected, and only the last one, without a next cursor, gets get_more_tools. */
    #[Group('ac-02-8')]
    public function testOnlyTheLastPageGetsGetMoreToolsAndEachKeepsTheLibrarysCursor(): void
    {
        $build = function (bool $tracked): HttpHarness {
            $builder = $this->builder()->setPaginationLimit(1)
                ->add(self::tool('a'), new RecordingTool())
                ->add(self::tool('b'), new RecordingTool());
            if ($tracked) {
                $this->track($builder);
            }

            return $this->harness($builder);
        };
        $twin = $build(false)->listTools();
        $harness = $build(true);

        $first = $harness->listTools();
        $this->assertIsString($first->nextCursor);
        $second = $harness->listTools($first->nextCursor);

        $this->assertSame(['a'], $this->names($first));
        $this->assertSame($twin->nextCursor, $first->nextCursor);
        $this->assertSame(['b', GetMoreTools::NAME], $this->names($second));
        $this->assertObjectNotHasProperty('nextCursor', $second);
    }

    #[Group('ac-02-9')]
    public function testAToolAddedAfterInstallIsListedAndInjected(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);
        $builder->addTool(static fn(string $text): string => $text, name: 'late', description: 'Late');

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['echo', 'late', GetMoreTools::NAME], $this->names($result));
        $this->assertSame(['text', 'session_id', 'context'], $this->keys(JsonAssert::at($result, 'tools', 1, 'inputSchema', 'properties')));
    }

    #[Group('ac-02-10')]
    public function testAThrowingCustomerHandlerGivesTheSameErrorAsWithoutAgentCat(): void
    {
        $build = function (bool $tracked): HttpHarness {
            $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
            if ($tracked) {
                $this->track($builder);
            }
            $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static function (): never {
                throw new \RuntimeException('list failed');
            }));

            return $this->harness($builder);
        };
        $twin = $build(false)->request('tools/list');

        $answer = $build(true)->request('tools/list');

        $this->assertObjectHasProperty('error', $answer);
        $this->assertEquals($twin->error, $answer->error);
        $this->assertSame(0, $this->queue->stats()['queueLength']);
    }

    public function testAJsonRpcErrorFromTheNextHandlerPassesUnchanged(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);
        $builder->addRequestHandler(new CustomerHandler(
            ListToolsRequest::class,
            static fn(Request $request): Error => Error::forInvalidParams('bad cursor', $request->getId()),
        ));

        $answer = $this->harness($builder)->request('tools/list');

        $this->assertEquals((object) ['code' => Error::INVALID_PARAMS, 'message' => 'bad cursor'], $answer->error);
    }

    public function testAnAnswerThatIsNotAToolListPassesUnchanged(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);
        $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static fn(): CallToolResult => new CallToolResult([new TextContent('odd')])));

        $result = $this->harness($builder)->listTools();

        $this->assertEquals((object) ['content' => [(object) ['type' => 'text', 'text' => 'odd']], 'isError' => false], $result);
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $this->logger->messages(), 'TS listWrap: the answer has no tools array');
    }

    #[Group('ac-02-11')]
    #[Group('ac-04-28')]
    public function testAnEmptyListingIsServedAsIs(): void
    {
        $builder = $this->builder();
        $server = $this->track($builder);

        $result = $this->harness($builder)->listTools();

        $this->assertSame([], $result->tools);
        $this->assertContains(ToolListInjector::NO_TOOLS_WARNING, $this->logger->messages());
        $this->assertNull($server->registry);
    }

    #[Group('ac-02-14')]
    #[Group('ac-04-24')]
    public function testACustomerGetMoreToolsIsListedOnceAndWarnedAboutOnce(): void
    {
        $builder = $this->builder()->add(self::tool(GetMoreTools::NAME, 'Mine'), new RecordingTool());
        $this->track($builder);
        $harness = $this->harness($builder);

        $harness->listTools();
        $result = $harness->listTools();

        $this->assertSame([GetMoreTools::NAME], $this->names($result));
        $this->assertSame('Mine', JsonAssert::at($result, 'tools', 0, 'description'));
        $this->assertCount(1, array_filter($this->logger->messages(), static fn(string $line): bool => str_contains($line, 'shadowed')));
    }

    #[Group('ac-02-15')]
    #[Group('ac-01-38')]
    #[Group('ac-01-83')]
    public function testInitializeAndListingRunNoHookAndQueueNothing(): void
    {
        $runs = 0;
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(identify: static function () use (&$runs): null {
            ++$runs;

            return null;
        }));

        $this->harness($builder)->listTools();
        $this->queue->flush();

        $this->assertSame(0, $runs);
        $this->assertSame([], $this->transport->deliveries);
    }

    #[Group('ac-04-21')]
    public function testAToolThatDeclaresSessionIdKeepsItsOwnAndIsReportedOnce(): void
    {
        $own = ['type' => 'string', 'description' => 'mine'];
        $builder = $this->builder()->add(
            new Tool(name: 'own', title: null, inputSchema: ['type' => 'object', 'properties' => ['session_id' => $own], 'required' => ['session_id']], description: null, annotations: null),
            new RecordingTool(),
        );
        $this->track($builder);
        $harness = $this->harness($builder);

        $harness->listTools();
        $result = $harness->listTools();

        $this->assertEquals((object) $own, JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties', 'session_id'));
        $prefix = 'ERROR: Tool "own" already declares a \'session_id\' parameter.';
        $this->assertCount(1, array_filter($this->logger->messages(), static fn(string $line): bool => str_starts_with($line, $prefix)));
    }

    #[Group('ac-04-26')]
    public function testAnOutputSchemaDeclaresMcpSessionAsOptional(): void
    {
        $builder = $this->builder()->add(
            new Tool(name: 'echo', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: null, annotations: null, outputSchema: ['type' => 'object', 'properties' => ['echoed' => ['type' => 'string']]]),
            new RecordingTool(),
        );
        $server = $this->track($builder);

        $result = $this->harness($builder)->listTools();

        $output = JsonAssert::at($result, 'tools', 0, 'outputSchema');
        $this->assertSame(['echoed', Constants::MCP_SESSION_KEY], $this->keys(JsonAssert::at($output, 'properties')));
        $this->assertObjectNotHasProperty('required', $this->object($output));
        $this->assertSame(['echo'], $server->outputRegistry);
    }

    #[Group('ac-01-26')]
    #[Group('ac-04-17')]
    public function testHookModeInjectsNoSessionId(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(resolveSessionId: static fn(): string => 'mine'));

        $result = $this->harness($builder)->listTools();

        foreach ([0, 1] as $index) {
            $schema = JsonAssert::at($result, 'tools', $index, 'inputSchema');
            $this->assertNotContains('session_id', $this->keys(JsonAssert::at($schema, 'properties')));
            $this->assertNotContains('session_id', $this->list(JsonAssert::at($schema, 'required')));
        }
    }

    #[Group('ac-01-14')]
    #[Group('ac-04-27')]
    public function testTracingOffInjectsNoHandlesButKeepsContextAndGetMoreTools(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(enableTracing: false, enableAgentTracking: true));

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($result));
        $this->assertSame(['msg', 'context'], $this->keys(JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties')));
    }

    #[Group('ac-01-16')]
    public function testReportMissingOffListsNoGetMoreTools(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(enableReportMissing: false));

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['echo'], $this->names($result));
        $this->assertSame(['msg', 'session_id', 'context'], $this->keys(JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties')));
    }

    #[Group('ac-01-17')]
    public function testContextOffInjectsNoContext(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(enableToolCallContext: false));

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['msg', 'session_id'], $this->keys(JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function contextDescriptions(): iterable
    {
        yield 'custom' => ['X', 'X'];
        yield 'empty' => ['', Constants::DEFAULT_CONTEXT_PARAMETER_DESCRIPTION];
    }

    #[DataProvider('contextDescriptions')]
    #[Group('ac-01-18')]
    #[Group('ac-01-19')]
    public function testTheContextDescriptionCanBeReplaced(string $custom, string $expected): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder, new Options(customContextDescription: $custom));

        $result = $this->harness($builder)->listTools();

        $this->assertSame($expected, JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties', 'context', 'description'));
        $this->assertSame(self::CONTEXT_DESCRIPTION, JsonAssert::at($result, 'tools', 1, 'inputSchema', 'properties', 'context', 'description'));
    }

    public function testACustomerListHandlerAddedAfterInstallGetsTheInjection(): void
    {
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);
        $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([self::tool('x')])));

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['x', GetMoreTools::NAME], $this->names($result));
        $this->assertSame(['session_id', 'context'], $this->keys(JsonAssert::at($result, 'tools', 0, 'inputSchema', 'properties')));
    }

    /**
     * The cursor a customer handler answers, and the names AgentCat lists. D66: a page with a next cursor on the wire
     * has no get_more_tools. mcp/sdk sends no `nextCursor` for a falsy one (`''`, `'0'`), so such a page is the last.
     *
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function cursors(): iterable
    {
        yield 'none' => [null, ['x', GetMoreTools::NAME]];
        yield 'empty' => ['', ['x', GetMoreTools::NAME]];
        yield 'zero' => ['0', ['x', GetMoreTools::NAME]];
        yield 'opaque' => ['c', ['x']];
    }

    /** @param list<string> $names */
    #[DataProvider('cursors')]
    public function testTheCursorIsSentWhenTheLibraryWouldSendIt(?string $cursor, array $names): void
    {
        $build = function (bool $tracked) use ($cursor): HttpHarness {
            $builder = $this->builder();
            if ($tracked) {
                $this->track($builder);
            }
            $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([self::tool('x')], $cursor)));

            return $this->harness($builder);
        };
        $twin = $build(false)->listTools();

        $result = $build(true)->listTools();

        $this->assertSame($names, $this->names($result));
        $this->assertSame($this->keys($twin), $this->keys($result));
        $this->assertSame($twin->nextCursor ?? null, $result->nextCursor ?? null);
    }

    public function testMembersALaterLibraryAddsToTheListingAreKept(): void
    {
        $listing = $this->listing(['tools' => [self::tool('x')], 'nextCursor' => 'c', '_meta' => ['source' => 'customer']]);
        $twin = $this->customerListing(false, $listing);

        $result = $this->customerListing(true, $listing);

        $this->assertSame(['x'], $this->names($result)); // D66: a page with a next cursor has no get_more_tools
        $this->assertSame(['tools', 'nextCursor', '_meta'], $this->keys($result));
        $this->assertSame($twin->nextCursor, $result->nextCursor);
        $this->assertEquals($twin->_meta, $result->_meta);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function listingsWithoutAToolList(): iterable
    {
        yield 'no tools member' => [['items' => [self::tool('x')]]];
        yield 'tools is an object' => [['tools' => ['x' => self::tool('x')]]];
    }

    /** @param array<string, mixed> $members */
    #[DataProvider('listingsWithoutAToolList')]
    public function testAListingWithoutAToolListOnTheWirePassesUnchanged(array $members): void
    {
        $listing = $this->listing($members);
        $twin = $this->customerListing(false, $listing);

        $result = $this->customerListing(true, $listing);

        $this->assertEquals($twin, $result);
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $this->logger->messages(), 'TS listWrap: `tools` is not an array');
    }

    public function testAFailedInjectionServesTheOriginalListing(): void
    {
        $tool = new class ('flaky', null, ['type' => 'object', 'properties' => [], 'required' => []], null, null) extends Tool {
            private bool $served = false;

            #[\Override]
            public function jsonSerialize(): array
            {
                if (!$this->served) {
                    $this->served = true;

                    throw new \RuntimeException('tree failed');
                }

                return parent::jsonSerialize();
            }
        };
        $builder = $this->builder();
        $this->track($builder);
        $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([$tool])));

        $result = $this->harness($builder)->listTools();

        $this->assertSame(['flaky'], $this->names($result));
        $this->assertContains('Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - tree failed', $this->logger->messages());
    }

    public function testAListingStartsTheDeliveryOfAWaitingEvent(): void
    {
        $this->transport->complete = false;
        $builder = $this->builder()->add($this->echoTool(), new RecordingTool());
        $this->track($builder);
        $harness = $this->harness($builder);
        $this->queue->add(new QueuedEvent(new Event(sessionId: 'ses_aaaaaaaaaaaaaaaaaaaaaaaaaaa', projectId: self::PROJECT, eventType: Constants::TOOLS_CALL_EVENT_TYPE), null, null, []));
        $this->assertSame(['queueLength' => 1, 'activeRequests' => 0], $this->queue->stats());

        $harness->listTools();

        $this->assertSame(['queueLength' => 0, 'activeRequests' => 1], $this->queue->stats());
        $this->assertCount(1, $this->transport->deliveries);
    }

    /** The listing a server gets when a customer handler added after install() answers `tools/list`. */
    private function customerListing(bool $tracked, ListToolsResult $answer): \stdClass
    {
        $builder = $this->builder();
        if ($tracked) {
            $this->track($builder);
        }
        $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => $answer));

        return $this->harness($builder)->listTools();
    }

    /**
     * A `ListToolsResult` whose wire members are exactly these, standing in for a later mcp/sdk whose result has
     * more members (for example `_meta`) or another shape.
     *
     * @param array<string, mixed> $members
     */
    private function listing(array $members): ListToolsResult
    {
        return new class ($members) extends ListToolsResult {
            /** @param array<string, mixed> $members */
            public function __construct(private readonly array $members)
            {
                parent::__construct([]);
            }

            /** @return array<string, mixed> */
            #[\Override] // @phpstan-ignore method.childReturnType (stands in for a later ListToolsResult with other members)
            public function jsonSerialize(): array
            {
                return $this->members;
            }
        };
    }

    private function builder(): Builder
    {
        return Server::builder()->setServerInfo('harness-server', '1.2.3');
    }

    private function track(Builder $builder, Options $options = new Options()): TrackedServer
    {
        $server = new TrackedServer(self::PROJECT, $options, McpSdkAdapter::serverInfo($builder), $this->publisher);
        McpSdkAdapter::install($builder, $server, $this->publisher, new CallInterceptor($this->publisher));

        return $server;
    }

    private function harness(Builder $builder): HttpHarness
    {
        $harness = new HttpHarness($builder->build());
        $harness->initialize();

        return $harness;
    }

    private function echoTool(): Tool
    {
        return new Tool(
            name: 'echo',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']],
            description: 'Echo',
            annotations: null,
        );
    }

    private static function tool(string $name, ?string $description = null): Tool
    {
        return new Tool(name: $name, title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: $description, annotations: null);
    }

    /** @return list<string> */
    private function names(\stdClass $result): array
    {
        $tools = $result->tools ?? null;
        $this->assertIsArray($tools);

        return array_map(function (mixed $tool): string {
            $name = JsonAssert::at($tool, 'name');
            $this->assertIsString($name);

            return $name;
        }, array_values($tools));
    }

    /** @return list<string> */
    private function keys(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        $this->assertIsList($value);

        return $value;
    }

    private function object(mixed $value): \stdClass
    {
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }
}
