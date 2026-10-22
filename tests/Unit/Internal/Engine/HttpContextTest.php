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
}
