<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\LaravelMcp;

use AgentCat\LaravelMcp\Internal\LaravelHttpContext;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-02-43')]
final class LaravelHttpContextTest extends TestCase
{
    public function testTheMethodTheUrlAndTheHeadersComeFromTheRequest(): void
    {
        $request = Request::create('https://api.test/mcp/weather?unit=c', 'POST', server: ['HTTP_X_AGENTCAT_HEALTH_RUN' => 'S', 'HTTP_AUTHORIZATION' => 'Bearer t']);

        $context = LaravelHttpContext::of($request);

        $this->assertSame('POST', $context->method);
        $this->assertSame('https://api.test/mcp/weather?unit=c', $context->url);
        $this->assertSame('S', $context->headers['x-agentcat-health-run'] ?? null);
        $this->assertSame('Bearer t', $context->headers['authorization'] ?? null);
    }

    /** Like `HttpContext::fromServer()`: Laravel's `fullUrl()` would give `https://example.test:8443/mcp?a=1&b=2`. */
    public function testTheUrlIsTheSchemeTheHostAndTheRequestUriAsSent(): void
    {
        $request = new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTPS' => 'on', 'HTTP_HOST' => 'Example.test:8443', 'REQUEST_URI' => '/mcp/?b=2&a=1', 'QUERY_STRING' => 'b=2&a=1']);

        $this->assertSame('https://Example.test:8443/mcp/?b=2&a=1', LaravelHttpContext::of($request)->url);
    }

    /** No `Host` header, no full URL (C15), as the SAPI reading has it; Symfony would fall back to `SERVER_NAME`. */
    public function testARequestWithoutAHostHeaderHasNoUrl(): void
    {
        $request = new Request(server: ['REQUEST_METHOD' => 'POST', 'SERVER_NAME' => 'example.test', 'REQUEST_URI' => '/mcp']);

        $this->assertNull(LaravelHttpContext::of($request)->url);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function credentials(): iterable
    {
        yield 'Basic, decoded by Symfony' => [['HTTP_AUTHORIZATION' => 'Basic dTpzZWNyZXQ='], 'Basic dTpzZWNyZXQ='];
        yield 'Basic, decoded by the SAPI too' => [['HTTP_AUTHORIZATION' => 'Basic dTpzZWNyZXQ=', 'PHP_AUTH_USER' => 'u', 'PHP_AUTH_PW' => 'secret'], 'Basic dTpzZWNyZXQ='];
        yield 'Digest' => [['HTTP_AUTHORIZATION' => 'Digest username="u", response="x"'], 'Digest username="u", response="x"'];
        // mod_php keeps only PHP_AUTH_*: the Authorization header that Symfony rebuilds from them is the client's.
        yield 'Basic, consumed by the SAPI' => [['PHP_AUTH_USER' => 'u', 'PHP_AUTH_PW' => 'secret'], 'Basic dTpzZWNyZXQ='];
    }

    /**
     * Symfony's `ServerBag` adds `php-auth-user`, `php-auth-pw` or `php-auth-digest`, which no client sends: the
     * Authorization header stays, verbatim (D06).
     *
     * @param array<string, string> $credentials
     */
    #[DataProvider('credentials')]
    public function testTheHeadersSymfonyMakesUpFromTheCredentialsAreLeftOut(array $credentials, string $authorization): void
    {
        $request = new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/mcp'] + $credentials);

        $this->assertSame(['host' => 'example.test', 'authorization' => $authorization], LaravelHttpContext::of($request)->headers);
    }

    public function testRepeatedValuesAreJoinedAndNamesAreLowerCase(): void
    {
        $request = Request::create('/mcp', 'GET');
        $request->headers->replace([]);
        $request->headers->set('X-Multi', ['a', 'b']);
        $request->headers->set('X-Empty', null);

        $this->assertSame(['x-multi' => 'a, b', 'x-empty' => ''], LaravelHttpContext::of($request)->headers);
    }
}
