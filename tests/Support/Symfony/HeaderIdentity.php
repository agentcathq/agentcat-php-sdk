<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\Identity;
use AgentCat\ToolCall;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * An `identify` service that names the actor after the call's `X-User` header. It also records the `X-User` of the
 * request that the `request_stack` holds when it runs, as Symfony code reads the current request, so a test sees
 * which request a hook would read.
 */
final class HeaderIdentity
{
    /** @var list<array{string, ?string}> per call: the call's user, and the user of the stack's current request */
    public array $seen = [];

    public function __construct(private readonly RequestStack $requests) {}

    public function __invoke(ToolCall $call): Identity
    {
        $user = $call->headers['x-user'] ?? 'nobody';
        $this->seen[] = [$user, $this->requests->getCurrentRequest()?->headers->get('X-User')];

        return new Identity($user);
    }
}
