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
    /**
     * The headers that Symfony's `ServerBag` makes up from the `Authorization` header or the SAPI's `PHP_AUTH_*`
     * variables. No client sends them, and two hold the credentials in clear text: the `Authorization` header stays
     * (D06).
     */
    private const array MADE_UP_HEADERS = ['php-auth-user', 'php-auth-pw', 'php-auth-digest'];

    /**
     * The URL and the headers as the client sent them, like the SAPI reading of `HttpContext::fromServer()`:
     * - the URL from the scheme, the `Host` header and the raw request URI (Laravel's `fullUrl()` sorts the query
     *   and drops a trailing slash);
     * - header names in lower case, as Symfony's `HeaderBag` keeps them; the values of a repeated header joined
     *   with `, `, like a SAPI joins them.
     */
    public static function of(Request $request): HttpContext
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (!in_array($name, self::MADE_UP_HEADERS, true)) {
                $headers[$name] = implode(', ', $values); // a null value is ''
            }
        }
        // The Host header, not the server variable: a request that a worker builds from PSR-7 has only the header.
        $url = HttpContext::url(['HTTPS' => $request->server->get('HTTPS'), 'HTTP_HOST' => $request->headers->get('host'), 'REQUEST_URI' => $request->server->get('REQUEST_URI')]);

        return new HttpContext($request->getMethod(), $url, $headers);
    }
}
