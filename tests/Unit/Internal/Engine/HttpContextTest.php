<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Engine\HttpContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-04-49')]
final class HttpContextTest extends TestCase
{
    public function testCommandLineSapisGiveNull(): void
    {
        $server = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test'];

        $this->assertNotInstanceOf(HttpContext::class, HttpContext::fromServer('cli', $server));
        $this->assertNotInstanceOf(HttpContext::class, HttpContext::fromServer('phpdbg', $server));
    }

    public function testMethodUrlAndHeadersAreReadVerbatim(): void
    {
        $context = HttpContext::fromServer('fpm-fcgi', [
            7 => 'seven',
            'HTTP_ACCEPT' => 5,
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/mcp?x=1',
            'HTTP_HOST' => 'api.test',
            'HTTPS' => 'on',
            'HTTP_X_AGENTCAT_HEALTH_RUN' => 'S',
            'HTTP_AUTHORIZATION' => 'Bearer t',
            'CONTENT_TYPE' => 'application/json',
            'argv' => [],
        ]);

        $this->assertInstanceOf(HttpContext::class, $context);
        $this->assertSame('POST', $context->method);
        $this->assertSame('https://api.test/mcp?x=1', $context->url);
        $this->assertSame(
            ['host' => 'api.test', 'x-agentcat-health-run' => 'S', 'authorization' => 'Bearer t', 'content-type' => 'application/json'],
            $context->headers,
        );
    }

    public function testContentLengthIsAHeader(): void
    {
        $context = HttpContext::fromServer('apache2handler', ['CONTENT_LENGTH' => '12', 'CONTENT_OTHER' => 'x']);

        $this->assertSame(['content-length' => '12'], $context?->headers);
    }

    public function testSchemeFollowsHttps(): void
    {
        $base = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'h.test', 'REQUEST_URI' => '/p'];

        $this->assertSame('http://h.test/p', HttpContext::fromServer('fpm-fcgi', $base)?->url);
        $this->assertSame('http://h.test/p', HttpContext::fromServer('fpm-fcgi', $base + ['HTTPS' => 'off'])?->url);
        $this->assertSame('http://h.test/p', HttpContext::fromServer('fpm-fcgi', $base + ['HTTPS' => 'OFF'])?->url);
        $this->assertSame('http://h.test/p', HttpContext::fromServer('fpm-fcgi', $base + ['HTTPS' => ''])?->url);
        $this->assertSame('https://h.test/p', HttpContext::fromServer('fpm-fcgi', $base + ['HTTPS' => '1'])?->url);
    }

    public function testHostWithoutUriGivesTheOrigin(): void
    {
        $this->assertSame('http://h.test', HttpContext::fromServer('fpm-fcgi', ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'h.test'])?->url);
        $this->assertSame('http://h.test', HttpContext::fromServer('fpm-fcgi', ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'h.test', 'REQUEST_URI' => 5])?->url);
    }

    public function testNoHostGivesNoUrl(): void
    {
        $context = HttpContext::fromServer('fpm-fcgi', ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/p']);

        $this->assertInstanceOf(HttpContext::class, $context);
        $this->assertNull($context->url);
        $this->assertNull(HttpContext::fromServer('fpm-fcgi', ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => ''])?->url);
        $this->assertNull(HttpContext::fromServer('fpm-fcgi', ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 3])?->url);
    }

    public function testNoMethodAndNoHeadersGiveNull(): void
    {
        $this->assertNotInstanceOf(HttpContext::class, HttpContext::fromServer('fpm-fcgi', ['REQUEST_URI' => '/x', 'argv' => []]));
        $this->assertNotInstanceOf(HttpContext::class, HttpContext::fromServer('fpm-fcgi', ['REQUEST_METHOD' => 5]));
    }

    public function testAHeaderWithoutAMethodIsEnough(): void
    {
        $context = HttpContext::fromServer('fpm-fcgi', ['HTTP_HOST' => 'h.test']);

        $this->assertInstanceOf(HttpContext::class, $context);
        $this->assertNull($context->method);
    }

    public function testRequestInfoHasHeadersThenUrlAndNoMethod(): void
    {
        $info = (new HttpContext('POST', 'https://a.test/x', ['host' => 'a.test']))->toRequestInfo();

        $this->assertSame('{"headers":{"host":"a.test"},"url":"https://a.test/x"}', json_encode($info, JSON_UNESCAPED_SLASHES));
        $this->assertSame(['headers', 'url'], array_keys(get_object_vars($info)));
    }

    public function testRequestInfoWithoutHeadersHoldsAnObject(): void
    {
        $info = (new HttpContext('POST', null, []))->toRequestInfo();

        $this->assertEquals(new \stdClass(), $info->headers);
        $this->assertSame('{"headers":{}}', json_encode($info, JSON_UNESCAPED_SLASHES));
    }

    public function testCaptureIsNullUnderTheCommandLine(): void
    {
        $this->assertNotInstanceOf(HttpContext::class, HttpContext::capture());
    }

    /** A framework's request object, read as `fromServer()` reads the SAPI: the URL as sent, through `url()`. */
    #[Group('ac-02-43')]
    public function testARequestObjectGivesItsMethodTheUrlAsSentAndItsHeaders(): void
    {
        $context = HttpContext::fromRequest('POST', ['host' => ['Example.test:8443'], 'x-agentcat-health-run' => ['S']], 'on', 'Example.test:8443', '/mcp/?b=2&a=1');

        $this->assertSame('POST', $context->method);
        $this->assertSame('https://Example.test:8443/mcp/?b=2&a=1', $context->url);
        $this->assertSame(['host' => 'Example.test:8443', 'x-agentcat-health-run' => 'S'], $context->headers);
    }

    public function testARequestObjectWithoutAHostHasNoUrl(): void
    {
        $this->assertNull(HttpContext::fromRequest('GET', [], null, null, '/mcp')->url);
        $this->assertSame('http://h.test', HttpContext::fromRequest('GET', [], 'off', 'h.test', null)->url);
    }

    /** The values of a repeated header joined with `, `, like a SAPI joins them; a null value is ''. */
    public function testRepeatedHeaderValuesAreJoined(): void
    {
        $context = HttpContext::fromRequest('GET', ['x-multi' => ['a', 'b'], 'x-empty' => [null], 'x-none' => []], null, null, null);

        $this->assertSame(['x-multi' => 'a, b', 'x-empty' => '', 'x-none' => ''], $context->headers);
    }

    /**
     * Symfony's `ServerBag` makes up `php-auth-user`, `php-auth-pw` and `php-auth-digest` from the credentials; no
     * client sends them, and two hold the credentials in clear text. The `Authorization` header stays (D06).
     */
    public function testTheMadeUpCredentialHeadersAreLeftOut(): void
    {
        $headers = ['authorization' => ['Basic dTpzZWNyZXQ='], 'php-auth-user' => ['u'], 'php-auth-pw' => ['secret'], 'php-auth-digest' => ['d'], 'php-auth-other' => ['o']];

        $this->assertSame(['authorization' => 'Basic dTpzZWNyZXQ=', 'php-auth-other' => 'o'], HttpContext::fromRequest('POST', $headers, null, null, null)->headers);
    }

    /** Symfony's `HttpKernel::handle()` adds `X-Php-Ob-Level` to the request it handles; the client did not send it. */
    public function testTheOutputBufferLevelThatSymfonyAddsIsLeftOut(): void
    {
        $headers = ['x-php-ob-level' => ['1'], 'x-php-ob-levels' => ['2'], 'accept' => ['application/json']];

        $this->assertSame(['x-php-ob-levels' => '2', 'accept' => 'application/json'], HttpContext::fromRequest('POST', $headers, null, null, null)->headers);
    }
}
