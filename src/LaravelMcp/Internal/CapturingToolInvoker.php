<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\ThrowableStash;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;

/**
 * laravel/mcp's tool invoker, which also keeps the tool's own exception for the request it ran for (plan E1 header
 * conflict 5). laravel/mcp turns every tool exception into an error result in `toErrorResponse()`, in the plain and
 * the streamed path alike. No constructor: laravel/mcp 1.0.0 has none and 1.0.2 has one with a default.
 *
 * @internal
 */
final class CapturingToolInvoker extends ToolInvoker
{
    private ?ThrowableStash $stash = null;

    private ?JsonRpcRequest $request = null;

    public function capturingInto(ThrowableStash $stash, JsonRpcRequest $request): static
    {
        $this->stash = $stash;
        $this->request = $request;

        return $this;
    }

    #[\Override]
    protected function toErrorResponse(\Throwable $e): Response
    {
        if ($this->stash instanceof ThrowableStash && $this->request instanceof JsonRpcRequest) {
            $this->stash->put($this->request, $e);
        }

        return parent::toErrorResponse($e);
    }
}
