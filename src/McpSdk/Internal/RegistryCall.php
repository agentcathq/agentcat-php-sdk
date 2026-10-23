<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\TrackedServer;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\ListToolsHandler;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * A `tools/call` of a server tracked after build(): the handler that the server's registry holds for each tool. The
 * library's handler finds the tool, checks the arguments (`InjectedSchemaValidator`) and calls this handler, which
 * runs the same interception as a Builder tracked before build(), with `$runner` in place of the library's handler:
 * a copy of it over the tools as they were registered. Its answer goes back to the library's handler: the result,
 * which that handler sends unchanged, or the tool's exception, rethrown, which that handler logs and answers as it
 * would without AgentCat.
 *
 * @internal
 */
final readonly class RegistryCall
{
    public function __construct(
        private TrackedServer $server,
        private CallInterceptor $interceptor,
        private Publisher $publisher,
        private CallToolHandler $runner,
        private ListToolsHandler $lister,
        private ThrowableStash $stash,
        private RequestReader $reader,
    ) {}

    /**
     * The tool handler for the registry. mcp/sdk injects a `RequestContext` parameter, which holds the request and the
     * session, into any tool handler.
     *
     * @return \Closure(RequestContext): mixed
     */
    public function handler(): \Closure
    {
        $call = $this;

        return static fn(RequestContext $context): mixed => $call->run($context->getRequest(), $context->getSession());
    }

    /** @throws \Throwable the tool's exception, or an `\UnexpectedValueException` for a request that is not a `tools/call` */
    private function run(Request $request, SessionInterface $session): mixed
    {
        $this->publisher->pump(); // stdio servers deliver between requests
        if (!$request instanceof CallToolRequest) {
            throw new \UnexpectedValueException(sprintf('AgentCat SDK compatibility error: mcp/sdk ran a tool for a %s request.', $request::getMethod()));
        }
        $lister = $this->lister;
        $seam = new McpCallSeam($request, $session, $this->runner, static fn(): ListToolsHandler => $lister, $this->stash, $this->reader);
        $answer = $this->interceptor->intercept($this->server, $seam);
        if ($answer instanceof Response) {
            return $answer->result;
        }

        throw $seam->thrown() ?? new \RuntimeException($answer->message);
    }
}
