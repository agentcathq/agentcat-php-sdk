<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\ServerInfo\ListFailure;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\McpSdk\Internal\BuiltServer;
use AgentCat\McpSdk\Internal\McpServerInfo;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\Thrown;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/** What `McpServerInfo` makes of the answers of an mcp/sdk list handler. */
final class McpServerInfoTest extends TestCase
{
    public function testEachListAsksItsOwnRequestWithTheCursor(): void
    {
        $asked = [];
        $session = new Session(new InMemorySessionStore());
        $handler = new CustomerHandler(Request::class, static fn(): ListResourcesResult => new ListResourcesResult([]));
        $info = new McpServerInfo(static function (Request $request) use (&$asked, $handler): RequestHandlerInterface {
            $asked[] = [$request::class, $request->getId(), JsonAssert::optional((object) get_object_vars($request), 'cursor')];

            return $handler;
        }, static fn(): null => null, false, $session);

        foreach ([ServerInfoSource::TOOLS, ServerInfoSource::RESOURCES, ServerInfoSource::RESOURCE_TEMPLATES, ServerInfoSource::PROMPTS] as $index => $list) {
            try {
                $info->page($list, $index === 0 ? null : 'c' . $index);
            } catch (ListFailure) {
                // the stub answers every list with resources: the other lists have no list in the answer
            }
        }

        $this->assertSame([
            [ListToolsRequest::class, 'agentcat-server-info', null],
            [ListResourcesRequest::class, 'agentcat-server-info', 'c1'],
            [ListResourceTemplatesRequest::class, 'agentcat-server-info', 'c2'],
            [ListPromptsRequest::class, 'agentcat-server-info', 'c3'],
        ], $asked);
    }

    public function testAPageIsTheItemsOnTheWireAndTheNextCursor(): void
    {
        $builder = Server::builder()->setSession()->setPaginationLimit(1)
            ->addPrompt(static fn(): string => 'a', 'first')
            ->addPrompt(static fn(): string => 'b', 'second');
        $info = BuiltServer::lists($builder->build(), new Session(new InMemorySessionStore()));

        $page = $info->page(ServerInfoSource::PROMPTS, null);
        $this->assertNotNull($page);
        [$items, $cursor] = $page;
        $this->assertSame(['first'], JsonAssert::column($items, 'name'));
        $this->assertIsString($cursor);
        $last = $info->page(ServerInfoSource::PROMPTS, $cursor);
        $this->assertNotNull($last);
        $this->assertSame(['second'], JsonAssert::column($last[0], 'name'));
        $this->assertNull($last[1]);
        $this->assertTrue($info->toolsAdvertised(), 'the registry of a server tracked after build() holds what AgentCat adds');
    }

    public function testAnEmptyCursorEndsTheList(): void
    {
        $info = $this->answering(new ListResourcesResult([], ''));

        $this->assertSame([[], null], $info->page(ServerInfoSource::RESOURCES, null));
    }

    public function testAServerWithoutAHandlerForAListHasNoPage(): void
    {
        $info = new McpServerInfo(static fn(): null => null, static fn(): null => null, false, new Session(new InMemorySessionStore()));

        $this->assertNull($info->page(ServerInfoSource::RESOURCES, null));
        $this->assertFalse($info->toolsAdvertised());
    }

    public function testAJsonRpcErrorOfTheHandlerFailsTheList(): void
    {
        $info = $this->answering(Error::forInvalidParams('bad cursor', 'agentcat-server-info'));

        $error = Thrown::by(ListFailure::class, static fn(): ?array => $info->page(ServerInfoSource::RESOURCES, 'x'));

        $this->assertSame('the handler answered with a JSON-RPC error', $error->getMessage());
    }

    public function testAnAnswerWithoutTheListFailsTheList(): void
    {
        $other = Thrown::by(ListFailure::class, fn(): ?array => $this->answering(new ListPromptsResult([]))->page(ServerInfoSource::RESOURCES, null));
        $this->assertSame('the answer of the handler has no list', $other->getMessage());

        $none = Thrown::by(ListFailure::class, fn(): ?array => $this->answering(new CallToolResult([]))->page(ServerInfoSource::TOOLS, null));
        $this->assertSame('the answer of the handler has no list', $none->getMessage());
    }

    public function testANameThatIsNoListOfAHeartbeatIsRefused(): void
    {
        $info = $this->answering(new ListResourcesResult([]));

        $error = Thrown::by(ListFailure::class, static fn(): ?array => $info->page('roots', null));

        $this->assertSame('the list is no list of a heartbeat', $error->getMessage());
    }

    public function testTheTitleAndTheInstructionsComeFromTheConfigurationAndAreEmptyWithoutOne(): void
    {
        $session = new Session(new InMemorySessionStore());
        $with = new McpServerInfo(static fn(): null => null, static fn(): Configuration => new Configuration(new Implementation('n', '1', title: 'The Title'), new ServerCapabilities(), instructions: 'Read me.'), false, $session);
        $bare = new McpServerInfo(static fn(): null => null, static fn(): Configuration => new Configuration(new Implementation('n', '1'), new ServerCapabilities()), false, $session);
        $none = new McpServerInfo(static fn(): null => null, static fn(): null => null, false, $session);

        $this->assertSame(['The Title', 'Read me.'], [$with->title(), $with->instructions()]);
        $this->assertSame([['n', '1'], [null, null]], [$with->server(), $none->server()]);
        $this->assertSame(['', ''], [$bare->title(), $bare->instructions()]);
        $this->assertSame(['', ''], [$none->title(), $none->instructions()]);
    }

    public function testABuiltServerGivesItsConfigurationAndTheFirstHandlerThatSupportsAList(): void
    {
        $customer = new CustomerHandler(ListPromptsRequest::class, static fn(): ListPromptsResult => new ListPromptsResult([]));
        $builder = Server::builder()->setServerInfo('built', '1.0.0', title: 'Built Title')->setInstructions('Built instructions.')->setSession()
            ->addPrompt(static fn(): string => 'a', 'own')
            ->addRequestHandler($customer);
        $server = $builder->build();
        $session = new Session(new InMemorySessionStore());

        foreach ([$server, $builder] as $tracked) {
            $info = BuiltServer::lists($tracked, $session);
            $this->assertSame(['Built Title', 'Built instructions.'], [$info->title(), $info->instructions()]);
            $this->assertSame([[], null], $info->page(ServerInfoSource::PROMPTS, null), 'the handler of the customer answers first, as for a client');
        }
        $this->assertSame(2, $customer->calls);
        $this->assertNull(BuiltServer::configuration(Server::builder()), 'a Builder has no configuration before build()');
    }

    /** A source whose every list request gets this answer. */
    private function answering(ResultInterface|Error $answer): McpServerInfo
    {
        $handler = new CustomerHandler(Request::class, static fn(): ResultInterface|Error => $answer);

        return new McpServerInfo(static fn(): RequestHandlerInterface => $handler, static fn(): null => null, false, new Session(new InMemorySessionStore()));
    }
}
