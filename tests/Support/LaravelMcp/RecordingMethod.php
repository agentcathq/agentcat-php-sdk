<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\LaravelMcp;

use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** A laravel/mcp method handler that records every request and answers `{"ok": true}`. */
final class RecordingMethod implements Method
{
    /** @var list<JsonRpcRequest> the requests, in order */
    public array $requests = [];

    /** @var list<mixed> what `$observe` returned in each call, in order */
    public array $seen = [];

    /** @param (\Closure(): mixed)|null $observe runs inside every call, while laravel/mcp runs the method */
    public function __construct(private readonly ?\Closure $observe = null) {}

    #[\Override]
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $this->requests[] = $request;
        if ($this->observe instanceof \Closure) {
            $this->seen[] = ($this->observe)();
        }

        return JsonRpcResponse::result($request->id, ['ok' => true]);
    }
}
