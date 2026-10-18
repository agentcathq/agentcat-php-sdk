<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers `initialize`: records the client info of a legacy handshake, then answers with the handler the server's
 * entry named before AgentCat's. The answer, and what that handler throws, pass unchanged.
 *
 * @internal
 */
final readonly class TrackedInitialize implements Method
{
    /** @param class-string<Method> $original the handler the server's entry named before AgentCat's */
    public function __construct(private string $original, private HandshakeClient $handshake) {}

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $this->handshake->record($request->params['clientInfo'] ?? null);

        return Delegate::handle($this->original, $request, $context);
    }
}
