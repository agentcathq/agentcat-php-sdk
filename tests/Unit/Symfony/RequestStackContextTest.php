<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Symfony;

use AgentCat\Internal\Engine\HttpContext;
use AgentCat\Internal\Logging\Log;
use AgentCat\Symfony\Internal\RequestStackContext;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** The HTTP request of an mcp/sdk call in a Symfony application: the main request of the `RequestStack`. */
#[Group('ac-02-43')]
final class RequestStackContextTest extends TestCase
{
    /** The server variables of a request that a worker builds: only what the client sent, no default header. */
    private const array SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTPS' => 'on',
        'HTTP_HOST' => 'a.test',
        'REQUEST_URI' => '/mcp?x=1',
        'QUERY_STRING' => 'x=1',
        'HTTP_ACCEPT' => 'application/json, text/event-stream',
        'CONTENT_TYPE' => 'application/json',
    ];

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    /** The `mcp:server` console command (stdio) has no main request. */
    public function testAnEmptyStackGivesNull(): void
    {
        $this->assertNotInstanceOf(HttpContext::class, RequestStackContext::of(new RequestStack())());
    }

    public function testTheMainRequestGivesItsMethodUrlAndHeaders(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(server: self::SERVER));

        $context = RequestStackContext::of($requests)();

        $this->assertInstanceOf(HttpContext::class, $context);
        $this->assertSame('POST', $context->method);
        $this->assertSame('https://a.test/mcp?x=1', $context->url);
        $this->assertSame(['host' => 'a.test', 'accept' => 'application/json, text/event-stream', 'content-type' => 'application/json'], $context->headers);
    }

    public function testASubRequestDoesNotChangeTheAnswer(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(server: self::SERVER));
        $context = RequestStackContext::of($requests);
        $main = $context();

        $requests->push(new Request(server: ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'sub.test', 'REQUEST_URI' => '/fragment', 'HTTP_X_SUB' => '1']));

        $this->assertEquals($main, $context());
        $this->assertSame('https://a.test/mcp?x=1', $context()?->url);
    }

    /** The stack is read when a call is recorded, not when the closure is made: a worker pushes a new request each time. */
    public function testEachCallReadsTheRequestOfItsMoment(): void
    {
        $requests = new RequestStack();
        $context = RequestStackContext::of($requests);

        $requests->push(new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test', 'HTTP_X_USER' => 'ann']));
        $ann = $context();
        $requests->pop();
        $requests->push(new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test', 'HTTP_X_USER' => 'bob']));
        $bob = $context();
        $requests->pop();

        $this->assertSame(['ann', 'bob'], [$ann?->headers['x-user'] ?? null, $bob?->headers['x-user'] ?? null]);
        $this->assertNotInstanceOf(HttpContext::class, $context());
    }

    /** As the Laravel bridge and the conformance scenario have it: the made-up `php-auth-*` headers are left out (D06). */
    public function testTheHeadersSymfonyMakesUpFromTheCredentialsAreLeftOut(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test', 'REQUEST_URI' => '/mcp', 'HTTP_AUTHORIZATION' => 'Basic dTpzZWNyZXQ=', 'PHP_AUTH_USER' => 'u', 'PHP_AUTH_PW' => 'secret']));

        $this->assertSame(['host' => 'a.test', 'authorization' => 'Basic dTpzZWNyZXQ='], RequestStackContext::of($requests)()?->headers);
    }

    /** As the mcp/sdk adapter and the Laravel bridge record it: Symfony's `getHost()` would refuse it. */
    public function testAnInvalidHostIsRecordedAsSent(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'bad host!', 'REQUEST_URI' => '/mcp']));

        $context = RequestStackContext::of($requests)();

        $this->assertSame('http://bad host!/mcp', $context?->url);
        $this->assertSame(['host' => 'bad host!'], $context->headers);
    }

    /** Symfony refuses an invalid `X-HTTP-Method-Override`, which the client sends: no HTTP context, and no line. */
    public function testARequestSymfonyCannotReadGivesNullWithoutALine(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $requests = new RequestStack();
        $requests->push(new Request(server: ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'a.test', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'NOT A METHOD']));

        $this->assertNotInstanceOf(HttpContext::class, RequestStackContext::of($requests)());
        $this->assertSame([], $logger->messages());
    }
}
