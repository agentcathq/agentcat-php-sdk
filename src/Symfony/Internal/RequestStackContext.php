<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

use AgentCat\Internal\Engine\HttpContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The HTTP context of an mcp/sdk call in a Symfony application: the main request of the `RequestStack`, read when the
 * call is recorded, not `$_SERVER`, so a Symfony Runtime worker (RoadRunner, Swoole, FrankenPHP worker mode) records
 * the request it serves (plan E2 header conflict 5).
 *
 * @internal
 */
final class RequestStackContext
{
    /**
     * @return \Closure(): ?HttpContext the main request of the stack when a call is recorded, by the rules of
     *                                  `HttpContext::fromRequest()`; null without a main request (the `mcp:server`
     *                                  console command, stdio). Never throws: when Symfony cannot read the request
     *                                  (it refuses an invalid `X-HTTP-Method-Override` header, which the client
     *                                  sends), it is null too, and nothing is logged
     */
    public static function of(RequestStack $requests): \Closure
    {
        return static function () use ($requests): ?HttpContext {
            $request = $requests->getMainRequest();

            return $request instanceof Request ? self::read($request) : null;
        };
    }

    private static function read(Request $request): ?HttpContext
    {
        try {
            return HttpContext::fromRequest($request->getMethod(), $request->headers->all(), $request->server->get('HTTPS'), $request->headers->get('host'), $request->server->get('REQUEST_URI'));
        } catch (\Throwable) {
            return null; // the client controls the request: this is no drift
        }
    }
}
