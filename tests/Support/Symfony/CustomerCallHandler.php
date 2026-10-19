<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\Tests\Support\ToolCalls;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * A customer's own `tools/call` handler, as an application registers it: a service that symfony/mcp-bundle
 * autoconfigures from `RequestHandlerInterface` (tag `mcp.request_handler`). It answers every tool with `customer` and
 * records the arguments it got under `ToolCalls::of(CustomerCallHandler::RECORDED_AS)`.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final class CustomerCallHandler implements RequestHandlerInterface
{
    public const string RECORDED_AS = 'customer-handler';

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /** @return Response<CallToolResult> */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof CallToolRequest);
        ToolCalls::record(self::RECORDED_AS, $request->arguments);

        return new Response($request->getId(), new CallToolResult([new TextContent('customer')]));
    }
}
