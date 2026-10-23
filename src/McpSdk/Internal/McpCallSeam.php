<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Engine\CallSeam;
use AgentCat\Internal\Engine\Observed;
use AgentCat\Internal\Engine\ThrowableStash;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Json\JsonTree;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * One mcp/sdk `tools/call` request for `CallInterceptor`. The library's answer is a JSON-RPC `Response` or `Error`.
 *
 * @implements CallSeam<Response<mixed>|Error>
 *
 * @internal
 */
final class McpCallSeam implements CallSeam
{
    /** The request sent to the library by `callStripped()`: the key of the stashed tool exception. */
    private ?CallToolRequest $sent = null;

    /** The tool's exception that `observe()` took from the stash. */
    private ?\Throwable $thrown = null;

    /**
     * @param RequestHandlerInterface<mixed> $delegate the handler that answers after AgentCat's
     * @param \Closure(): (RequestHandlerInterface<mixed>|null) $listDelegate the handler that lists after AgentCat's
     */
    public function __construct(
        private readonly CallToolRequest $request,
        private readonly SessionInterface $session,
        private readonly RequestHandlerInterface $delegate,
        private readonly \Closure $listDelegate,
        private readonly ThrowableStash $stash,
        private readonly RequestReader $reader,
    ) {}

    #[\Override]
    public function toolName(): string
    {
        return $this->request->name;
    }

    #[\Override]
    public function arguments(): mixed
    {
        return JsonTree::object($this->request->arguments);
    }

    #[\Override]
    public function describe(): CallContext
    {
        return $this->reader->read($this->request, $this->session);
    }

    /** @return Response<mixed>|Error */
    #[\Override]
    public function callOriginal(): Response|Error
    {
        return $this->delegate->handle($this->request, $this->session);
    }

    /**
     * Removes the names from the native arguments, so nested values keep the PHP types mcp/sdk decoded, and sends a
     * copy with the same id and `_meta`.
     *
     * @param list<string> $names
     *
     * @return Response<mixed>|Error
     */
    #[\Override]
    public function callStripped(array $names): Response|Error
    {
        $arguments = $this->request->arguments;
        foreach ($names as $name) {
            unset($arguments[$name]);
        }
        $stripped = (new CallToolRequest($this->request->name, $arguments))->withId($this->request->getId());
        $meta = $this->request->getMeta();
        $this->sent = $meta === null ? $stripped : $stripped->withMeta($meta);

        return $this->delegate->handle($this->sent, $this->session);
    }

    /** The exception of the tool, once `observe()` ran: null when the tool did not throw. */
    public function thrown(): ?\Throwable
    {
        return $this->thrown;
    }

    /** @param Response<mixed>|Error $result */
    #[\Override]
    public function observe(mixed $result): Observed
    {
        $thrown = $this->thrown = $this->stash->take($this->sent);

        return $result instanceof Error
            ? new Observed(protocolError: $result->message, thrown: $thrown)
            : new Observed(Trees::result($result->result), thrown: $thrown);
    }

    /**
     * @param Response<mixed>|Error $result
     *
     * @return Response<mixed>|Error
     */
    #[\Override]
    public function replace(mixed $result, \stdClass $tree): Response|Error
    {
        // A JSON-RPC error is never decorated: it reaches the client as the library built it.
        return $result instanceof Response
            ? new Response($result->getId(), new WireCallToolResult(Trees::callToolResult($result->result), $tree))
            : $result;
    }

    /** @return Response<WireCallToolResult> */
    #[\Override]
    public function answer(\stdClass $tree): Response
    {
        return new Response($this->request->getId(), new WireCallToolResult(Trees::callToolResult($tree), $tree));
    }

    #[\Override]
    public function listOriginal(?string $cursor): ?array
    {
        $delegate = ($this->listDelegate)();
        if (!$delegate instanceof RequestHandlerInterface) {
            return null;
        }
        $answer = $delegate->handle((new ListToolsRequest($cursor))->withId('agentcat-rebuild'), $this->session);
        $listing = $answer instanceof Response ? Trees::listing($answer->result) : null;

        return $listing === null ? null : [$listing[2], ToolListing::nextCursor($listing[1]->nextCursor ?? null)];
    }

    /** mcp/sdk answers are complete when `handle()` returns: a tool that waits suspends its fiber inside it. */
    #[\Override]
    public function later(mixed $result, \Closure $complete, \Closure $fail): null
    {
        return null;
    }
}
