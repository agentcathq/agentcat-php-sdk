<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Identity;
use Illuminate\Http\Request;

/**
 * An `identify` hook with the request injected into its constructor: the container builds it when the hook runs, with
 * the request of that moment. It names the actor after the `X-User` header and ignores the `ToolCall` it gets.
 */
final readonly class InjectedRequestIdentity
{
    public function __construct(private Request $request) {}

    public function __invoke(): Identity
    {
        return new Identity($this->request->header('X-User', 'nobody'));
    }
}
