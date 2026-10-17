<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\Delegates;
use AgentCat\McpSdk\Internal\ListToolsInterceptor;
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

final class DelegatesTest extends TestCase
{
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

        $this->assertTrue($delegates->available());
        $this->assertSame($self, $delegates->after($before, $request));
        $this->assertSame($after, $delegates->after($self, $request));
        $this->assertInstanceOf(ListToolsHandler::class, $delegates->after($after, $request));
        $this->assertNull($delegates->after($this->lister(), $request), 'a handler that is not in the list has no next one');
        $this->assertSame([], $this->logger->messages());
    }

    public function testNotFoundIsTheLibrarysAnswerWhenNoHandlerSupportsTheRequest(): void
    {
        $error = Delegates::notFound((new ListToolsRequest())->withId(7));

        $this->assertSame(Error::METHOD_NOT_FOUND, $error->code);
        $this->assertSame('No handler found for method "tools/list".', $error->message);
        $this->assertSame(7, $error->getId());
    }

    public function testTheInterceptorAnswersNotFoundWhenNoHandlerFollowsIt(): void
    {
        $builder = Server::builder();
        $builder->build();
        $interceptor = new ListToolsInterceptor(
            new TrackedServer('proj', new Options(), static fn(): array => [null, null]),
            new Delegates($builder), // the interceptor is not in this Builder's handlers
            new Publisher(new EventQueue(transport: new RecordingTransport())),
        );
        $request = (new ListToolsRequest())->withId(3);

        $this->assertTrue($interceptor->supports($request));
        $this->assertFalse($interceptor->supports((new CallToolRequest('echo', []))->withId(4)));
        $this->assertEquals(Delegates::notFound($request), $interceptor->handle($request, new Session(new InMemorySessionStore())));
    }

    private function lister(): CustomerHandler
    {
        return new CustomerHandler(ListToolsRequest::class, static fn(): ListToolsResult => new ListToolsResult([]));
    }
}
