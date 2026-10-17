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
        return $request instanceof ListToolsRequest && $this->delegates->available();
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
            $tools = ToolListing::apply($this->server, Trees::tools($response->result->tools));
            if ($tools === null) {
                return $response;
            }
            $body = new \stdClass();
            $body->tools = $tools;
            $cursor = $response->result->nextCursor;
            if (!in_array($cursor, [null, '', '0'], true)) { // ListToolsResult::jsonSerialize() uses PHP truthiness
                $body->nextCursor = $cursor;
            }

            return new Response($response->getId(), new WireResult($body));
        } catch (\Throwable $e) {
            Log::write('Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - ' . ErrorText::message($e));

            return $response;
        }
    }
}
