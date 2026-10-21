<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\AgentCat;
use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\LaravelMcp\Internal\Access\ServerAccess;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\LaravelMcp\ConformanceServer;
use AgentCat\Tests\Support\LaravelMcp\PagedOwnServer;
use AgentCat\Tests\Support\LaravelMcp\PagedServer;
use AgentCat\Tests\Support\LaravelMcp\Tools\EchoTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\OwnTool;
use AgentCat\Tests\Support\LaravelMcp\Tools\StructuredTool;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\ToolCalls;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Testing\TestListResponse;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use PHPUnit\Framework\Attributes\Group;

/**
 * A customer's Laravel test suite with AgentCat installed (D31): laravel/mcp's `Server::tool()` and `Server::tools()`
 * helpers, with `agentcat.enabled` unset as in the published configuration.
 */
final class CustomerTestSuiteTest extends BridgeTestCase
{
    /** The tools of `ConformanceServer`, in its order. */
    private const array TOOLS = ['echo', 'structured', 'refuses', 'boom', 'own'];

    /** `TestListResponse` has no assertion on its whole list: a macro gives the listed names. */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        TestListResponse::macro('names', fn(): array => array_column($this->items, 'name'));
    }

    #[\Override]
    protected function tearDown(): void
    {
        TestListResponse::flushMacros();
        parent::tearDown();
    }

    public function testWithoutAFakeTheHelpersSeeTheLibrarysOwnAnswers(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        ConformanceServer::tool(EchoTool::class, ['msg' => 'hi'])->assertSee('echo: hi')->assertHasNoErrors();
        ConformanceServer::tool(StructuredTool::class)->assertStructuredContent(['echoed' => 'x']);
        $tools = ConformanceServer::tools();
        $resolved = $this->app?->make(ConformanceServer::class, ['transport' => new FakeTransporter()]);

        $this->assertSame(self::TOOLS, $this->names($tools));
        $this->assertInstanceOf(ConformanceServer::class, $resolved);
        $methods = ServerAccess::methods($resolved); // laravel/mcp's own method classes, as without AgentCat
        $this->assertSame([CallTool::class, ListTools::class], [$methods['tools/call'] ?? null, $methods['tools/list'] ?? null]);
        $this->assertSame(ServerAccess::methods(new ConformanceServer(new FakeTransporter())), $methods);
        $this->assertSame([], $logger->messages());
        $this->assertFalse(EventQueue::global()->hasWork());
    }

    public function testAFakeRecordsTheHelpersCalls(): void
    {
        $fake = AgentCat::fake();

        ConformanceServer::tool(EchoTool::class, ['msg' => 'hi', 'session_id' => 'start'])->assertSee('echo: hi');
        ConformanceServer::tool(StructuredTool::class, ['session_id' => 'start'])->assertStructuredContent(static function (AssertableJson $json): void {
            $json->where('echoed', 'x')->etc(); // the helpers still read PHP arrays
        });

        $fake->assertTrackedCount(2);
        $this->assertSame(['echo', 'structured'], array_map(static fn(Event $event): ?string => $event->resourceName, $fake->events()));
    }

    /** A tracked listing ends with AgentCat's tool: `tools()` follows `nextCursor`, and it comes once, after the last page (D66). */
    public function testWithAFakeTheListingEndsWithTheGetMoreToolsTool(): void
    {
        AgentCat::fake();

        $this->assertSame([...self::TOOLS, GetMoreTools::NAME], $this->names(ConformanceServer::tools()));
    }

    /** D66 (B10 fixed): `tools()` follows `nextCursor` on one server, and only the last page has AgentCat's tool. */
    #[Group('ac-02-8')]
    public function testWithAFakeAPagedListingEndsWithOneGetMoreTools(): void
    {
        AgentCat::fake();

        $this->assertSame(['echo', ...array_map(static fn(int $i): string => sprintf('t%02d', $i), range(1, 15)), GetMoreTools::NAME], $this->names(PagedServer::tools()));
    }

    /** D66 (B10 fixed): each `tool()` builds a new server, whose rebuild reads up to page 2, where `own` is. */
    #[Group('ac-02-19')]
    #[Group('ac-02-20')]
    public function testWithAFakeAToolOnTheSecondPageKeepsItsOwnSessionId(): void
    {
        $fake = AgentCat::fake();

        PagedOwnServer::tool(OwnTool::class, ['session_id' => 'theirs'])->assertSee('ok');

        $this->assertSame([['session_id' => 'theirs']], ToolCalls::of('own'));
        $fake->assertTrackedCount(1);
    }

    /** @return list<mixed> */
    private function names(TestListResponse $tools): array
    {
        $names = $tools->__call('names', []); // the macro of setUp(), which static analysis cannot see
        $this->assertIsArray($names);

        return array_values($names);
    }
}
