<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\TrackedServer;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers `tools/call` first (TS callWrap): `CallInterceptor` resolves the handles, strips them, lets the next
 * handler run the tool, decorates the answer and queues one event.
 *
 * @implements RequestHandlerInterface<mixed>
 *
 * @internal
 */
final readonly class CallToolInterceptor implements RequestHandlerInterface
{
    public function __construct(
        private TrackedServer $server,
        private Delegates $delegates,
        private ListToolsInterceptor $list,
        private CallInterceptor $interceptor,
        private Publisher $publisher,
        private ThrowableStash $stash,
        private RequestReader $reader,
    ) {}

    /** The tracking state of the server: a server built from a tracked Builder finds it here. */
    public function server(): TrackedServer
    {
        return $this->server;
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        // Without a handler to delegate to (mcp/sdk internals changed), the library answers on its own.
        return $request instanceof CallToolRequest && $this->delegates->after($this, $request) instanceof RequestHandlerInterface;
    }

    /** @return Response<mixed>|Error */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $this->publisher->pump(); // stdio servers deliver between requests
        $delegate = $this->delegates->after($this, $request);
        if (!$request instanceof CallToolRequest || !$delegate instanceof RequestHandlerInterface) {
            return Delegates::notFound($request);
        }
        $delegates = $this->delegates;
        $list = $this->list;
        $seam = new McpCallSeam(
            $request,
            $session,
            $delegate,
            static fn(): ?RequestHandlerInterface => $delegates->after($list, (new ListToolsRequest())->withId('agentcat-rebuild')),
            $this->stash,
            $this->reader,
        );

        return $this->interceptor->intercept($this->server, $seam);
    }
}
