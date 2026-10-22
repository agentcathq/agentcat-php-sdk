<?php

declare(strict_types=1);

namespace AgentCat\LaravelMcp\Internal\Access;

use AgentCat\Internal\Access\TouchesPrivateMember;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Transport\HttpTransport;

/**
 * The protected member of laravel/mcp's HTTP transport that AgentCat reads (D21): the request the server answers,
 * for the HTTP context of its events. Reads only. The closure is bound to `HttpTransport`, so PHPStan checks the
 * member in its scope.
 *
 * @internal
 */
final class HttpTransportAccess
{
    #[TouchesPrivateMember(class: HttpTransport::class, member: 'request', type: Request::class, visibility: 'protected')]
    public static function request(HttpTransport $transport): Request
    {
        $read = \Closure::bind(static fn(HttpTransport $t): Request => $t->request, null, HttpTransport::class);

        return $read($transport);
    }
}
