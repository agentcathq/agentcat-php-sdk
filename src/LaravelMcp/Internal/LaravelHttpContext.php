<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal;

use AgentCat\Internal\Engine\HttpContext;
use Illuminate\Http\Request;

/**
 * The HTTP context of the request a laravel/mcp server answers, read from that request rather than from `$_SERVER`,
 * so Octane's workers record it too (plan E1 header conflict 9).
 *
 * @internal
 */
final class LaravelHttpContext
{
    /** The URL and the headers as the client sent them, by the rules of `HttpContext::fromRequest()`. */
    public static function of(Request $request): HttpContext
    {
        return HttpContext::fromRequest($request->getMethod(), $request->headers->all(), $request->server->get('HTTPS'), $request->headers->get('host'), $request->server->get('REQUEST_URI'));
    }
}
