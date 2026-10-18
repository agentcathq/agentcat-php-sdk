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
     * Header names in lower case, as Symfony's `HeaderBag` keeps them; the values of a repeated header joined with
     * `, `, like a SAPI joins them.
     */
    public static function of(Request $request): HttpContext
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', $values); // a null value is ''
        }

        return new HttpContext($request->getMethod(), $request->fullUrl(), $headers);
    }
}
