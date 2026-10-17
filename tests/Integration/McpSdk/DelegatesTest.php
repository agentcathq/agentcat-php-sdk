<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\CallToolInterceptor;
use AgentCat\McpSdk\Internal\Delegates;
use AgentCat\McpSdk\Internal\ListToolsInterceptor;
use AgentCat\McpSdk\Internal\RequestReader;
use AgentCat\McpSdk\Internal\ThrowableStash;
use AgentCat\Options;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server;
use Mcp\Server\Handler\Request\ListToolsHandler;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class DelegatesTest extends TestCase
{
    private const string COMPATIBILITY = 'AgentCat SDK Compatibility: AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

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

    public function testTheNextHandlerIsTheFirstOneAfterTheCallerThatSupportsTheRequest(): void
    {
        $before = $this->lister();
        $self = $this->lister();
        $calls = new CustomerHandler(CallToolRequest::class, static fn(): ListToolsResult => new ListToolsResult([]));
        $after = $this->lister();
        $builder = Server::builder()->addRequestHandler($before)->addRequestHandler($self)->addRequestHandler($calls)->addRequestHandler($after);
        $builder->build();
        $delegates = new Delegates($builder);
        $request = new ListToolsRequest();

        $this->assertSame($self, $delegates->after($before, $request));
        $this->assertSame($after, $delegates->after($self, $request));
        $this->assertInstanceOf(ListToolsHandler::class, $delegates->after($after, $request));
        $this->assertSame([], $this->logger->messages());
    }

    public function testACallerMissingFromTheListHasNoNextHandlerAndIsReportedOnce(): void
    {
        $builder = Server::builder();
        $builder->build();
        $delegates = new Delegates($builder);

        $this->assertNull($delegates->after($this->lister(), new ListToolsRequest()));
        $this->assertNull($delegates->after($this->lister(), new ListToolsRequest()));
        $this->assertSame([['level' => LogLevel::WARNING, 'message' => self::COMPATIBILITY]], $this->logger->records);
    }

    public function testNotFoundIsTheLibrarysAnswerWhenNoHandlerSupportsTheRequest(): void
    {
        $error = Delegates::notFound((new ListToolsRequest())->withId(7));

        $this->assertSame(Error::METHOD_NOT_FOUND, $error->code);
        $this->assertSame('No handler found for method "tools/list".', $error->message);
        $this->assertSame(7, $error->getId());
    }

    public function testTheInterceptorStepsAsideWhenNoHandlerFollowsIt(): void
    {
        $builder = Server::builder();
        $builder->build();
        $interceptor = new ListToolsInterceptor(
            new TrackedServer('proj', new Options(), static fn(): array => [null, null]),
            new Delegates($builder), // the interceptor is not in this Builder's handlers
            new Publisher(new EventQueue(transport: new RecordingTransport())),
        );
        $request = (new ListToolsRequest())->withId(3);

        $this->assertFalse($interceptor->supports($request), 'the library answers instead');
        $this->assertFalse($interceptor->supports((new CallToolRequest('echo', []))->withId(4)));
        // A caller that ignores supports() gets the library's own answer for a request nobody handles.
        $this->assertEquals(Delegates::notFound($request), $interceptor->handle($request, new Session(new InMemorySessionStore())));
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    public function testTheCallInterceptorStepsAsideWhenNoHandlerFollowsIt(): void
    {
        $builder = Server::builder();
        $builder->build();
        $server = new TrackedServer('proj', new Options(), static fn(): array => [null, null]);
        $delegates = new Delegates($builder); // the interceptors are not in this Builder's handlers
        $publisher = new Publisher(new EventQueue(transport: new RecordingTransport()));
        $interceptor = new CallToolInterceptor(
            $server,
            $delegates,
            new ListToolsInterceptor($server, $delegates, $publisher),
            new CallInterceptor($publisher),
            $publisher,
            new ThrowableStash(),
            new RequestReader(),
        );
        $call = (new CallToolRequest('echo', []))->withId(4);
        $list = (new ListToolsRequest())->withId(5);
        $session = new Session(new InMemorySessionStore());

        $this->assertFalse($interceptor->supports($call), 'the library answers instead');
        $this->assertFalse($interceptor->supports($list));
        // A caller that ignores supports() gets the library's own answer for a request nobody handles.
        $this->assertEquals(Delegates::notFound($call), $interceptor->handle($call, $session));
        $this->assertEquals(Delegates::notFound($list), $interceptor->handle($list, $session));
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    private function lister(): CustomerHandler
    {
        return new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([]));
    }
}
