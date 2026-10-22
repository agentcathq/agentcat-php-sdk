<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Event;
use AgentCat\Identity;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Options;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\ListingLines;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\McpSdk\GreetingTool;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\McpSdkVersion;
use AgentCat\Tests\Support\McpSdk\NameService;
use AgentCat\Tests\Support\McpSdk\RecordingTool;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use AgentCat\ToolCall;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Registry\Container;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class CallToolTest extends TestCase
{
    private const string SESSION = 'ses_bbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

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

    #[Group('ac-02-16')]
    #[Group('ac-04-29')]
    #[Group('ac-02-47')]
    public function testTheToolGetsItsOwnArgumentsAndTheEventRecordsTheCall(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame([['msg' => 'hi']], $echo->calls);
        $this->assertSame('{"content":[{"type":"text","text":"echo: hi"}],"isError":false}', $this->json(HttpHarness::result($answer)));
        $event = $this->fixture->event();
        $this->assertSame('echo', $event->resourceName);
        $this->assertSame(Constants::TOOLS_CALL_EVENT_TYPE, $event->eventType);
        $this->assertSame(
            '{"method":"tools/call","params":{"name":"echo","arguments":{"msg":"hi","context":"why","session_id":"' . self::SESSION . '"}}}',
            $this->json(JsonAssert::at($event->parameters, 'request')),
        );
        $this->assertSame('why', $event->userIntent);
        $this->assertSame(self::SESSION, $event->sessionId);
        $this->assertSame('supplied', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        $this->assertSame(['harness-server', '1.2.3'], [$event->serverName, $event->serverVersion]);
        $this->assertSame(['harness-client', '1.0.0'], [$event->clientName, $event->clientVersion]);
        $this->assertSame(TrackingFixture::PROJECT, $event->projectId);
        $this->assertNull($event->isError);
        $this->assertNull($event->error);
    }

    #[Group('ac-02-17')]
    public function testAToolThatAllowsNoExtraPropertiesStillAcceptsTheInjectedOnes(): void
    {
        $builder = TrackingFixture::builder();
        $strict = Tools::strict($builder);
        $this->fixture->track($builder, new Options(enableAgentTracking: true));
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $answer = $harness->callTool('strict', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION, 'agent_id' => 'a1']);

        $this->assertFalse(JsonAssert::at(HttpHarness::result($answer), 'isError'));
        $this->assertSame([['msg' => 'hi']], $strict->calls);
    }

    #[Group('ac-02-18')]
    public function testArgumentsAgentCatDidNotInjectReachTheTool(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $harness->callTool('echo', ['msg' => 'hi', 'extra_key' => 'x', 'context' => 'why', 'session_id' => 'start']);

        $this->assertSame([['msg' => 'hi', 'extra_key' => 'x']], $echo->calls);
    }

    #[Group('ac-02-19')]
    #[Group('ac-04-13')]
    public function testAToolThatDeclaresSessionIdKeepsIt(): void
    {
        $builder = TrackingFixture::builder();
        $own = Tools::own($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $harness->callTool('own', ['session_id' => 'mine', 'context' => 'why']);

        $this->assertSame([['session_id' => 'mine']], $own->calls);
        $event = $this->fixture->event();
        $this->assertSame('', $event->sessionId);
        $this->assertSame('foreign', $this->tag($event, Constants::TAG_SESSION_SOURCE));
    }

    #[Group('ac-02-20')]
    public function testAServerThatNeverListedRebuildsItsRegistriesAndStripsExactly(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $own = Tools::own($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);

        $harness->callTool('echo', ['msg' => 'hi', 'agent_id' => 'a1', 'session_id' => 'start']);
        $harness->callTool('own', ['session_id' => 'mine']);

        $this->assertSame([['msg' => 'hi', 'agent_id' => 'a1']], $echo->calls, 'agent tracking is off: agent_id is not ours');
        $this->assertSame([['session_id' => 'mine']], $own->calls);
        $rebuilt = array_filter($this->fixture->lines(), static fn(string $line): bool => $line === 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)');
        $this->assertCount(1, $rebuilt, 'the first call rebuilds, the second finds the registries');
        $this->assertCount(2, $this->fixture->events());
    }

    #[Group('ac-02-21')]
    public function testAFailedRebuildStripsHeuristicallyAndIsTriedAgain(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder);
        $builder->addRequestHandler(new CustomerHandler(ListToolsRequest::class, static function (): never {
            throw new \RuntimeException('list failed');
        }));
        $harness = $this->fixture->harness($builder);

        $harness->callTool('echo', ['msg' => 'hi', 'agent_id' => 'a1', 'session_id' => 'start']);
        $harness->callTool('echo', ['msg' => 'again']);

        $this->assertSame([['msg' => 'hi'], ['msg' => 'again']], $echo->calls);
        $warning = 'Warning: registry rebuild-on-demand failed - RuntimeException: list failed';
        $this->assertSame([$warning, $warning], $this->fixture->lines());
        $this->assertCount(2, $this->fixture->events());
    }

    /** @return iterable<string, array{bool}> */
    public static function eras(): iterable
    {
        yield 'handshake' => [false];
        yield '2026-07-28' => [true];
    }

    /**
     * D66 (B10 fixed): a server that never listed (every PHP-FPM request) rebuilds from page 1 to the page that names
     * the tool, so the 51st tool, alone on page 2 of mcp/sdk's pages of 50, keeps its own required `session_id`.
     */
    #[DataProvider('eras')]
    #[Group('ac-02-19')]
    #[Group('ac-02-20')]
    public function testTheFiftyFirstToolKeepsItsOwnSessionId(bool $modern): void
    {
        $builder = TrackingFixture::builder();
        foreach (range(1, 50) as $i) {
            Tools::named($builder, sprintf('t%02d', $i));
        }
        $own = Tools::own($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $arguments = ['session_id' => 'theirs', 'agent_id' => 'a1'];

        $answer = $modern ? $harness->callToolModern('own', $arguments) : $harness->callTool('own', $arguments);

        $this->assertObjectNotHasProperty('error', $answer); // without D66: -32602, "Missing required properties"
        $this->assertSame([['session_id' => 'theirs', 'agent_id' => 'a1']], $own->calls);
        $this->assertSame('foreign', $this->tag($this->fixture->event(), Constants::TAG_SESSION_SOURCE));
        $this->assertSame([ListingLines::ownSessionId('own'), self::REBUILT], $this->fixture->lines());
    }

    /** D66 (B10 fixed): each listed page merges into the registries, so a tool of an earlier page is stripped exactly. */
    #[Group('ac-02-8')]
    public function testListedPagesMergeIntoTheRegistries(): void
    {
        $builder = TrackingFixture::builder()->setPaginationLimit(1);
        $echo = Tools::echo($builder);
        $own = Tools::own($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $cursor = JsonAssert::at($harness->listTools(), 'nextCursor');
        $this->assertIsString($cursor);
        $harness->listTools($cursor);

        $harness->callTool('echo', ['msg' => 'hi', 'agent_id' => 'a1', 'session_id' => 'start']);
        $harness->callTool('own', ['session_id' => 'mine']);

        $this->assertSame([['msg' => 'hi', 'agent_id' => 'a1']], $echo->calls, 'agent tracking is off: agent_id is not ours');
        $this->assertSame([['session_id' => 'mine']], $own->calls);
        $this->assertSame([ListingLines::ownSessionId('own')], $this->fixture->lines(), 'both tools were listed: no rebuild');
    }

    /**
     * D66 (B10 fixed): the rebuild stops at the first page that names the tool. A customer list handler that ignores
     * the cursor gives the same next cursor again. The rebuild for a tool that no page names reads two pages and stops
     * at that cursor, as at the last page; the call is served with the heuristic strip.
     */
    #[Group('ac-02-20')]
    #[Group('ac-04-30')]
    public function testAListHandlerThatIgnoresTheCursorEndsTheRebuild(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder);
        $listed = new Tool(name: 'listed', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: 'Listed', annotations: null);
        $list = new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([$listed], 'again'));
        $builder->addRequestHandler($list);
        $harness = $this->fixture->harness($builder);

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'agent_id' => 'a1', 'session_id' => 'start', 'context' => 'why']);

        $this->assertSame(2, $list->calls); // page 1, then page "again", whose cursor was already followed
        $this->assertSame([['msg' => 'hi']], $echo->calls); // no page names echo: the heuristic strip
        $this->assertSame('echo: hi', JsonAssert::at(HttpHarness::result($answer), 'content', 1, 'text'));
        $this->assertSame([self::REBUILT], $this->fixture->lines());
    }

    /**
     * D66: the rebuild reads the cursor that the client gets. mcp/sdk sends no `nextCursor` for a falsy one, so a page
     * whose next cursor is `'0'` is the last page for the rebuild too, as for the listing.
     */
    #[Group('ac-02-20')]
    public function testANextCursorTheLibraryDoesNotSendEndsTheRebuild(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder);
        $listed = new Tool(name: 'listed', title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => []], description: 'Listed', annotations: null);
        $list = new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([$listed], '0'));
        $builder->addRequestHandler($list);
        $harness = $this->fixture->harness($builder);

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertSame(1, $list->calls);
        $this->assertSame([['msg' => 'hi']], $echo->calls);
        $this->assertSame([self::REBUILT], $this->fixture->lines());
    }

    /**
     * D66: a get_more_tools call rebuilds like any other tool. A server that never listed (PHP-FPM): the rebuild finds
     * the customer's own get_more_tools on page 2. AgentCat answers the call (report-missing shadows the customer's
     * tool), and the `session_id` that tool declares stays the customer's: no session is read from it.
     */
    #[DataProvider('eras')]
    #[Group('ac-02-19')]
    #[Group('ac-02-14')]
    public function testAShadowedCustomerGetMoreToolsKeepsItsOwnSessionId(bool $modern): void
    {
        $builder = TrackingFixture::builder();
        foreach (range(1, 50) as $i) {
            Tools::named($builder, sprintf('t%02d', $i));
        }
        $customer = new RecordingTool();
        $schema = ['type' => 'object', 'properties' => ['session_id' => ['type' => 'string']], 'required' => ['session_id']];
        $builder->add(new Tool(name: GetMoreTools::NAME, title: null, inputSchema: $schema, description: 'Mine', annotations: null), $customer);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $arguments = ['session_id' => 'theirs', 'context' => 'need X'];

        $answer = $modern ? $harness->callToolModern(GetMoreTools::NAME, $arguments) : $harness->callTool(GetMoreTools::NAME, $arguments);

        $this->assertSame([$this->cannedText()], $this->texts(HttpHarness::result($answer)));
        $this->assertSame([], $customer->calls, 'AgentCat answers its calls');
        $event = $this->fixture->event();
        $this->assertSame('', $event->sessionId);
        $this->assertSame('foreign', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        $this->assertSame(ListingLines::shadowedCall(6), $this->fixture->lines());
    }

    /**
     * D66 (B10 fixed): a server that never listed rebuilds up to the page that names the tool, so a page-2 tool with an
     * `outputSchema` is in the output registry and gets the `mcp_session` mirror. Page 1 alone would leave it out.
     */
    #[Group('ac-02-36')]
    #[Group('ac-02-20')]
    public function testAPageTwoToolWithAnOutputSchemaGetsTheSessionMirror(): void
    {
        $builder = TrackingFixture::builder();
        foreach (range(1, 50) as $i) {
            Tools::named($builder, sprintf('t%02d', $i));
        }
        Tools::structured($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);

        $result = HttpHarness::result($harness->callTool('structured', ['session_id' => 'start']));

        $structured = JsonAssert::at($result, 'structuredContent');
        $this->assertSame([Constants::MCP_SESSION_KEY, 'echoed'], $this->keys($structured));
        $this->assertSame('issued', JsonAssert::at($structured, Constants::MCP_SESSION_KEY, 'status'));
        $this->assertSame([self::REBUILT], $this->fixture->lines());
    }

    /**
     * D66 (B10 fixed): on a long-running server a page listed again replaces only its own entries. After page 1, page 2
     * and page 1 again, the tools of page 2 keep their entry, their own `session_id` and their mirror, with no rebuild.
     */
    #[Group('ac-02-8')]
    #[Group('ac-02-19')]
    public function testListingAnEarlierPageAgainKeepsTheLaterPagesEntries(): void
    {
        $builder = TrackingFixture::builder()->setPaginationLimit(2);
        Tools::echo($builder);
        Tools::named($builder, 't01');
        $own = Tools::own($builder);
        Tools::structured($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $cursor = JsonAssert::at($harness->listTools(), 'nextCursor');
        $this->assertIsString($cursor);
        $second = JsonAssert::at($harness->listTools($cursor), 'tools');
        $this->assertIsList($second);
        $this->assertSame(['own', 'structured', GetMoreTools::NAME], array_map(static fn(mixed $tool): mixed => JsonAssert::at($tool, 'name'), $second));
        $harness->listTools();

        $harness->callTool('own', ['session_id' => 'mine', 'agent_id' => 'a1']);
        $result = HttpHarness::result($harness->callTool('structured', ['session_id' => 'start']));

        $this->assertSame([['session_id' => 'mine', 'agent_id' => 'a1']], $own->calls, 'agent tracking is off: agent_id is not ours');
        $this->assertSame('foreign', $this->tag($this->fixture->event(0, 2), Constants::TAG_SESSION_SOURCE));
        $this->assertSame([Constants::MCP_SESSION_KEY, 'echoed'], $this->keys(JsonAssert::at($result, 'structuredContent')));
        $this->assertSame([ListingLines::ownSessionId('own')], $this->fixture->lines(), 'no rebuild');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function sessionlessArguments(): iterable
    {
        yield 'no session_id' => [['msg' => 'hi']];
        yield 'start' => [['msg' => 'hi', 'session_id' => 'start']];
        yield 'START with spaces' => [['msg' => 'hi', 'session_id' => ' START ']];
    }

    /** @param array<string, string> $arguments */
    #[DataProvider('sessionlessArguments')]
    #[Group('ac-02-30')]
    #[Group('ac-04-35')]
    public function testANewSessionIsIssuedInTheFirstContentBlock(array $arguments): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool('echo', $arguments));

        $event = $this->fixture->event();
        $this->assertMatchesRegularExpression('/\Ases_[0-9A-Za-z]{27}\z/', $event->sessionId);
        $this->assertSame([$this->issued($event->sessionId), 'echo: hi'], $this->texts($result));
        $this->assertSame('minted', $this->tag($event, Constants::TAG_SESSION_SOURCE));
        $this->assertSame([['msg' => 'hi']], $echo->calls);
    }

    #[Group('ac-02-31')]
    #[Group('ac-04-37')]
    public function testAnUnrecognizedSessionIsReportedInTheFirstContentBlock(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'not-a-real-id']));

        $this->assertSame([Constants::MINT_BACK_HEADER_UNRECOGNIZED . "\n" . Constants::MINT_BACK_UNRECOGNIZED_BODY, 'echo: hi'], $this->texts($result));
        $event = $this->fixture->event();
        $this->assertSame('', $event->sessionId);
        $this->assertSame('invalid', $this->tag($event, Constants::TAG_SESSION_SOURCE));
    }

    #[Group('ac-02-36')]
    #[Group('ac-02-38')]
    #[Group('ac-04-39')]
    public function testStructuredOutputStartsWithTheIssuedSession(): void
    {
        $builder = TrackingFixture::builder();
        Tools::structured($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool('structured', ['session_id' => 'start']));

        $event = $this->fixture->event();
        $structured = JsonAssert::at($result, 'structuredContent');
        $this->assertSame([Constants::MCP_SESSION_KEY, 'echoed'], $this->keys($structured));
        $this->assertSame(
            $this->json(['session_id' => $event->sessionId, 'status' => 'issued']),
            $this->json(JsonAssert::at($structured, Constants::MCP_SESSION_KEY)),
        );
        $this->assertSame('{"echoed":"x"}', $this->json(JsonAssert::at($event->response, 'structuredContent')), 'the mirror is wire-only');
    }

    #[Group('ac-02-37')]
    #[Group('ac-04-39')]
    public function testStructuredOutputEchoesTheAgentAndTheActiveSession(): void
    {
        $builder = TrackingFixture::builder();
        Tools::structured($builder);
        $this->fixture->track($builder, new Options(enableAgentTracking: true));
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool('structured', ['session_id' => self::SESSION, 'agent_id' => 'a1']));

        $this->assertSame(
            $this->json(['session_id' => self::SESSION, 'agent_id' => 'a1', 'status' => 'active']),
            $this->json(JsonAssert::at($result, 'structuredContent', Constants::MCP_SESSION_KEY)),
        );
        $this->assertSame(['x'], $this->texts($result), 'a supplied session announces nothing');
        $this->assertSame('a1', $this->tag($this->fixture->event(), Constants::TAG_AGENT_ID));
    }

    /**
     * mcp/sdk cannot tell `{}` from `[]`, so an empty structuredContent is an empty object to the mirror and to the
     * event. On 0.9.0 and main, mcp/sdk itself rejects `[]` against an object outputSchema first.
     */
    public function testAnEmptyStructuredContentCountsAsAnEmptyObject(): void
    {
        $builder = TrackingFixture::builder();
        Tools::structured($builder, []);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool('structured', ['session_id' => 'start']));

        $event = $this->fixture->event();
        if (McpSdkVersion::hasModernEra()) {
            $this->assertTrue(JsonAssert::at($result, 'isError'));
            $texts = $this->texts($result);
            $this->assertCount(2, $texts);
            $this->assertSame($this->issued($event->sessionId), $texts[0]);
            $this->assertIsString($texts[1]);
            $this->assertStringStartsWith("Invalid structured output for tool 'structured'", $texts[1]);
            $this->assertObjectNotHasProperty('structuredContent', $result);
            $this->assertTrue($event->isError);

            return;
        }
        $structured = JsonAssert::at($result, 'structuredContent');
        $this->assertSame([Constants::MCP_SESSION_KEY], $this->keys($structured));
        $this->assertSame('{}', $this->json(JsonAssert::at($event->response, 'structuredContent')));
        $this->assertNull($event->isError);
    }

    #[Group('ac-02-40')]
    #[Group('ac-04-46')]
    public function testGetMoreToolsIsAnsweredByAgentCat(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        $customer = new CustomerHandler(CallToolRequest::class, static fn(): CallToolResult => new CallToolResult([new TextContent('customer')]));
        $builder->addRequestHandler($customer);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool(GetMoreTools::NAME, ['context' => 'need X']));

        $event = $this->fixture->event();
        $this->assertSame([$this->issued($event->sessionId), $this->cannedText()], $this->texts($result));
        $this->assertSame(0, $customer->calls, 'no customer code runs');
        $this->assertSame(GetMoreTools::NAME, $event->resourceName);
        $this->assertSame('need X', $event->userIntent);
        $this->assertContains('Missing tool reported (context length: 6)', $this->fixture->lines());
    }

    #[Group('ac-02-41')]
    #[Group('ac-04-48')]
    public function testWithReportMissingOffGetMoreToolsIsAnUnknownTool(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder, new Options(enableReportMissing: false));
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $answer = $harness->callTool(GetMoreTools::NAME, ['context' => 'need X']);

        $this->assertEquals((object) ['code' => Error::INVALID_PARAMS, 'message' => 'Tool not found: "get_more_tools".'], $answer->error);
        $event = $this->fixture->event();
        $this->assertTrue($event->isError);
        $this->assertSame('Tool not found: "get_more_tools".', JsonAssert::at($event->error, 'message'));
    }

    #[Group('ac-02-42')]
    #[Group('ac-01-15')]
    public function testWithTracingOffNothingIsPublishedOrStripped(): void
    {
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder);
        $this->fixture->track($builder, new Options(enableTracing: false));
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $more = HttpHarness::result($harness->callTool(GetMoreTools::NAME, ['context' => 'need X']));
        $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why']);

        $this->assertSame([$this->cannedText()], $this->texts($more));
        $this->assertSame([['msg' => 'hi', 'context' => 'why']], $echo->calls, 'B06 parity');
        $this->assertSame([], $this->fixture->events());
    }

    #[Group('ac-01-20')]
    #[Group('ac-01-23')]
    public function testAgentTrackingWithoutAnAgentIdAddsNoAgentTags(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder, new Options(enableAgentTracking: true));
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame(['echo: hi'], $this->texts(HttpHarness::result($answer)));
        $event = $this->fixture->event();
        $this->assertSame('why', $event->userIntent);
        $this->assertSame([Constants::TAG_SESSION_SOURCE => 'supplied'], $event->tags);
    }

    /** Integer-like keys and nested empty objects reach the tool as mcp/sdk decodes them. */
    public function testTheToolGetsTheSameArrayAsWithoutAgentCat(): void
    {
        $properties = ['0' => ['type' => 'string'], '123' => ['type' => 'string'], 'nested' => ['type' => 'object']];
        $arguments = ['msg' => 'm', '0' => 'zero', '123' => 'x', 'nested' => ['a' => new \stdClass()]];
        $twinBuilder = TrackingFixture::builder();
        $twin = Tools::echo($twinBuilder, $properties);
        $this->fixture->harness($twinBuilder)->callTool('echo', $arguments);
        $builder = TrackingFixture::builder();
        $echo = Tools::echo($builder, $properties);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $harness->callTool('echo', $arguments + ['session_id' => 'start']);

        $this->assertCount(1, $twin->calls);
        $this->assertSame($twin->calls, $echo->calls);
        $this->assertSame(['msg', '0', '123', 'nested', 'session_id'], $this->keys(JsonAssert::at($this->fixture->event()->parameters, 'request', 'params', 'arguments')));
    }

    /** A reference handler set after track() replaces AgentCat's; only the stashed exception is lost. */
    public function testAReferenceHandlerSetAfterTrackStillGivesEvents(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        Tools::boom($builder);
        $this->fixture->track($builder);
        $theirs = $this->countingReferenceHandler();
        $builder->setReferenceHandler($theirs);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $echo = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $boom = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertSame(['echo: hi'], $this->texts(HttpHarness::result($echo)));
        $this->assertEquals((object) ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while executing tool'], $boom->error);
        $this->assertCount(2, $theirs);
        $this->assertSame('echo', $this->fixture->event(0, of: 2)->resourceName);
        $this->assertSame('{"message":"Error while executing tool","platform":"php"}', $this->json($this->fixture->event(1, of: 2)->error));
    }

    public function testAReferenceHandlerSetBeforeTrackRunsTheToolsAndKeepsTheException(): void
    {
        $builder = TrackingFixture::builder();
        Tools::boom($builder);
        $theirs = $this->countingReferenceHandler();
        $builder->setReferenceHandler($theirs);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $boom = $harness->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertEquals((object) ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while executing tool'], $boom->error);
        $this->assertCount(1, $theirs);
        $this->assertSame('RuntimeException', JsonAssert::at($this->fixture->event()->error, 'type'));
    }

    /** A tools/call handler added after track() answers, and AgentCat records its answer. */
    public function testACustomerCallHandlerAddedAfterTrackIsRecorded(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        /** @var list<Request> $received */
        $received = [];
        $customer = new CustomerHandler(CallToolRequest::class, static function (Request $request) use (&$received): CallToolResult {
            $received[] = $request;

            return new CallToolResult([new TextContent('mine')]);
        });
        $builder->addRequestHandler($customer);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $result = HttpHarness::result($harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION], ['progressToken' => 'p1']));

        $this->assertSame(['mine'], $this->texts($result));
        $this->assertSame(1, $customer->calls);
        $request = $received[0] ?? null;
        $this->assertInstanceOf(CallToolRequest::class, $request);
        $this->assertSame(['msg' => 'hi'], $request->arguments, 'the stripped copy');
        $this->assertSame(['progressToken' => 'p1'], $request->getMeta());
        $this->assertSame(JsonAssert::at($this->fixture->event()->parameters, 'extra', 'requestId'), $request->getId());
        $this->assertSame('{"content":[{"type":"text","text":"mine"}],"isError":false}', $this->json($this->fixture->event()->response));
    }

    /** @return iterable<string, array{bool}> */
    public static function containerMoments(): iterable
    {
        yield 'set before track()' => [true];
        yield 'set after track()' => [false];
    }

    /** The customer's container builds the tool handlers, whenever it was set. */
    #[DataProvider('containerMoments')]
    public function testTheCustomersContainerResolvesTheToolHandlers(bool $beforeTrack): void
    {
        $builder = TrackingFixture::builder()->addTool(GreetingTool::class, name: 'greet');
        $container = new Container();
        $container->set(GreetingTool::class, new GreetingTool('hello'));
        if ($beforeTrack) {
            $builder->setContainer($container);
        }
        $this->fixture->track($builder);
        if (!$beforeTrack) {
            $builder->setContainer($container);
        }
        $harness = $this->fixture->harness($builder);

        $result = HttpHarness::result($harness->callTool('greet', ['name' => 'Ada', 'session_id' => self::SESSION]));

        $this->assertSame(['hello, Ada'], $this->texts($result));
        $this->assertSame('greet', $this->fixture->event()->resourceName);
    }

    /** Without a customer container, AgentCat and mcp/sdk share one, so a class has one instance as before. */
    public function testAToolHandlerThatIsAlsoACompletionProviderHasOneInstance(): void
    {
        $builder = TrackingFixture::builder()
            ->addTool(NameService::class, name: 'name')
            ->addPrompt(
                static fn(#[CompletionProvider(provider: NameService::class)] string $name): array => [['role' => 'user', 'content' => $name]],
                name: 'greeting',
            );
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);

        $tool = $this->texts(HttpHarness::result($harness->callTool('name', ['name' => 'Ada', 'session_id' => self::SESSION])));
        $completion = HttpHarness::result($harness->request('completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'greeting'],
            'argument' => ['name' => 'name', 'value' => 'A'],
        ]));

        $this->assertCount(1, $tool);
        $this->assertSame($tool, JsonAssert::at($completion, 'completion', 'values'));
    }

    /** Each event of a long-lived server keeps its own handles and identity. */
    public function testInterleavedCallsKeepTheirOwnValues(): void
    {
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder, new Options(
            enableAgentTracking: true,
            identify: static fn(ToolCall $call): Identity => new Identity(is_string($call->arguments['msg'] ?? null) ? $call->arguments['msg'] : ''),
        ));
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $harness->callTool('echo', ['msg' => 'u1', 'agent_id' => 'a1', 'session_id' => 'start']);
        $harness->callTool('echo', ['msg' => 'u2', 'agent_id' => 'a2', 'session_id' => self::SESSION]);

        $first = $this->fixture->event(0, of: 2);
        $second = $this->fixture->event(1, of: 2);
        $this->assertSame(['a1', 'minted', 'u1'], [$this->tag($first, Constants::TAG_AGENT_ID), $this->tag($first, Constants::TAG_SESSION_SOURCE), $first->identifyActorGivenId]);
        $this->assertSame(['a2', 'supplied', 'u2'], [$this->tag($second, Constants::TAG_AGENT_ID), $this->tag($second, Constants::TAG_SESSION_SOURCE), $second->identifyActorGivenId]);
        $this->assertNotSame(self::SESSION, $first->sessionId);
        $this->assertSame(self::SESSION, $second->sessionId);
    }

    public function testACallStartsTheDeliveryOfTheEventBeforeIt(): void
    {
        $this->fixture->transport->complete = false;
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $harness->callTool('echo', ['msg' => 'one']);
        $before = count($this->fixture->transport->deliveries);
        $harness->callTool('echo', ['msg' => 'two']);

        $this->assertCount(1, $this->fixture->transport->deliveries);
        $this->assertSame(0, $before, 'the first call only queues its event');
        $this->assertSame(['queueLength' => 1, 'activeRequests' => 1], $this->fixture->queue->stats());
    }

    /** A reference handler that runs mcp/sdk's own and counts its calls. */
    private function countingReferenceHandler(): ReferenceHandlerInterface&\Countable
    {
        return new class implements ReferenceHandlerInterface, \Countable {
            /** @var int<0, max> */
            private int $calls = 0;

            private readonly ReferenceHandler $inner;

            public function __construct()
            {
                $this->inner = new ReferenceHandler();
            }

            #[\Override]
            public function handle(ElementReference $reference, array $arguments): mixed
            {
                ++$this->calls;

                return $this->inner->handle($reference, $arguments);
            }

            #[\Override]
            public function count(): int
            {
                return $this->calls;
            }
        };
    }

    private function tag(Event $event, string $key): ?string
    {
        return $event->tags[$key] ?? null;
    }

    private function issued(string $sessionId): string
    {
        return implode("\n", [Constants::MINT_BACK_HEADER_ISSUED, 'session_id: ' . $sessionId, Constants::MINT_BACK_ISSUED_BODY]);
    }

    private function cannedText(): string
    {
        $text = JsonAssert::at(Golden::json('handles/strings.json'), 'GET_MORE_TOOLS_RESULT', 'content', 0, 'text');
        $this->assertIsString($text);

        return $text;
    }

    /** @return list<mixed> the `text` of every content block */
    private function texts(\stdClass $result): array
    {
        $content = $result->content ?? null;
        $this->assertIsList($content);

        return array_map(static fn(mixed $block): mixed => JsonAssert::at($block, 'text'), $content);
    }

    /** @return list<string> */
    private function keys(mixed $object): array
    {
        $this->assertInstanceOf(\stdClass::class, $object);

        return array_map(strval(...), array_keys(get_object_vars($object)));
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
