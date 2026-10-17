<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\McpSdk;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * A request handler a customer adds with `Builder::addRequestHandler()`: it answers one request class with what
 * `$respond` returns (a result, or a JSON-RPC error), or throws what `$respond` throws.
 *
 * @implements RequestHandlerInterface<ResultInterface>
 */
final class CustomerHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    /**
     * @param class-string<Request> $request
     * @param \Closure(Request): (ResultInterface|Error) $respond
     */
    public function __construct(
        private readonly string $request,
        private readonly \Closure $respond,
    ) {}

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof $this->request;
    }

    /** @return Response<ResultInterface>|Error */
    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        ++$this->calls;
        $answer = ($this->respond)($request);

        return $answer instanceof Error ? $answer : new Response($request->getId(), $answer);
    }
}
