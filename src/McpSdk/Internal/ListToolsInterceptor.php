<?php

declare(strict_types=1);

namespace AgentCat\McpSdk\Internal;

use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ErrorText;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers `tools/list` first (TS listWrap): the next handler lists, then AgentCat injects its parameters and
 * appends get_more_tools. Errors, exceptions and other answers pass unchanged (02 AC-10).
 *
 * @implements RequestHandlerInterface<mixed>
 *
 * @internal
 */
final readonly class ListToolsInterceptor implements RequestHandlerInterface
{
    public function __construct(
        private TrackedServer $server,
        private Delegates $delegates,
        private Publisher $publisher,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        // Without a handler to delegate to (mcp/sdk internals changed), the library answers on its own.
        return $request instanceof ListToolsRequest && $this->delegates->after($this, $request) instanceof RequestHandlerInterface;
    }

    /** @return Response<mixed>|Error */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $this->publisher->pump(); // stdio servers deliver between requests
        $delegate = $this->delegates->after($this, $request);
        if (!$delegate instanceof RequestHandlerInterface) {
            return Delegates::notFound($request);
        }
        $response = $delegate->handle($request, $session); // throws and JSON-RPC errors pass unchanged (02 AC-10)
        if (!$response instanceof Response || !$response->result instanceof ListToolsResult) {
            return $response;
        }
        try {
            $tree = Trees::of($response->result); // the library's own wire tree: AgentCat changes only its `tools`
            if (!$tree instanceof \stdClass || !isset($tree->tools) || !is_array($tree->tools) || !array_is_list($tree->tools)) {
                return $response; // no tool list on the wire
            }
            $tools = ToolListing::apply($this->server, $tree->tools);
            if ($tools === null) {
                return $response;
            }
            $tree->tools = $tools;

            return new Response($response->getId(), new WireResult($tree));
        } catch (\Throwable $e) {
            Log::write('Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - ' . ErrorText::message($e));

            return $response;
        }
    }
}
