<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\Internal\Delivery\FinishRequest;
use AgentCat\Symfony\Internal\AnswerLength;
use AgentCat\Tests\Support\Symfony\SymfonyHttp;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use AgentCat\Tests\Support\Symfony\TestKernel;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * D62 through Symfony's HTTP kernel: on a SAPI that sends a response only when the script ends, the plain answer of a
 * tracked symfony/mcp-bundle server's route gets its `Content-Length`. The tests run under the CLI, so each one that
 * needs the listener registers it as `AgentCatBundle::boot()` does on such a SAPI. Each test boots its own kernel,
 * which compiles a container: 0.2 to 0.5 s measured on PHP 8.3 and 8.5.
 */
#[Group('ac-03-1')]
final class AnswerLengthTest extends SymfonyTestCase
{
    private const string PROJECT = 'proj_lllllllllllllllllllllllllll';

    /** Under the CLI the bundle adds no listener: the SAPI has no request that ends with the script. */
    #[MaximumDuration(1000)]
    public function testThisSapiGetsNoListener(): void
    {
        $this->assertFalse(FinishRequest::responseEndsWithTheScript(PHP_SAPI, $_SERVER, function_exists(...)));
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));

        $response = $this->toolCall($kernel);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame([], array_filter($this->listeners($kernel, KernelEvents::RESPONSE), static fn(mixed $listener): bool => $listener instanceof AnswerLength));
    }

    /** Last of `kernel.response`, after Symfony's own listeners: the answer is final when it gets its length. */
    #[MaximumDuration(1000)]
    public function testTheListenerRunsLast(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));

        AnswerLength::register($kernel->getContainer(), true);

        $listeners = $this->listeners($kernel, KernelEvents::RESPONSE);
        $this->assertInstanceOf(AnswerLength::class, end($listeners));
    }

    #[MaximumDuration(1000)]
    public function testATrackedServersAnswerGetsItsLength(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        AnswerLength::register($kernel->getContainer(), true);

        $response = $this->toolCall($kernel);

        $this->assertSame(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('echo: hi', $content);
        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));
        $this->assertCount(1, $this->deliveries(), 'the server was tracked');
    }

    /** One kernel, two servers: the route of the server AgentCat tracks gets the length, the excluded server's not. */
    #[MaximumDuration(1000)]
    public function testOnlyATrackedServersRouteGetsTheLength(): void
    {
        $kernel = $this->kernel(agentcat: [...$this->on(self::PROJECT), 'exclude_servers' => ['second']], mcp: TestKernel::TWO_SERVERS);
        AnswerLength::register($kernel->getContainer(), true);

        $excluded = $this->toolCall($kernel, '/mcp/second');
        $tracked = $this->toolCall($kernel);

        $this->assertSame(200, $excluded->getStatusCode());
        $this->assertFalse($excluded->headers->has('Content-Length'));
        $this->assertTrue($tracked->headers->has('Content-Length'));
        $this->assertCount(1, $this->deliveries(), 'only the conformance server was tracked');
    }

    #[MaximumDuration(1000)]
    public function testAnAnswerWhileAgentCatIsOffStaysAsItIs(): void
    {
        $kernel = $this->kernel(agentcat: [...$this->on(self::PROJECT), 'enabled' => 'false']);
        AnswerLength::register($kernel->getContainer(), true);

        $response = $this->toolCall($kernel);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame([], $this->deliveries());
    }

    /** An SSE answer streams after the kernel: it has no length to give. */
    #[MaximumDuration(1000)]
    public function testAStreamedAnswerStaysAsItIs(): void
    {
        $kernel = $this->streamingKernel($this->on(self::PROJECT));
        AnswerLength::register($kernel->getContainer(), true);
        $http = $this->http($kernel);
        $http->listTools();

        $response = $kernel->handle($http->callRequest('progress', ['msg' => 'hi', 'session_id' => 'start'], ['progressToken' => 'p1']));

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** mcp/sdk answers OPTIONS with 204, which has no body and must carry no `Content-Length` (RFC 9110 §8.6). */
    #[MaximumDuration(1000)]
    public function testAnAnswerWithoutABodyStaysAsItIs(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        AnswerLength::register($kernel->getContainer(), true);
        $this->toolCall($kernel); // the server is built and tracked

        $response = $kernel->handle(Request::create('http://127.0.0.1' . SymfonyHttp::PATH, 'OPTIONS'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /**
     * Symfony serves a HEAD request to the route as GET (mcp/sdk answers it with an error), and `Response::prepare()`
     * empties the body: a length of what is left would not be the GET answer's (RFC 9110 §8.6).
     */
    #[MaximumDuration(1000)]
    public function testAnAnswerToAHeadRequestStaysAsItIs(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        AnswerLength::register($kernel->getContainer(), true);
        $this->toolCall($kernel); // the server is built and tracked

        $response = $kernel->handle(Request::create('http://127.0.0.1' . SymfonyHttp::PATH, 'HEAD'));

        $this->assertSame('', $response->getContent());
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** A request no MCP route answers, in the same kernel after a tracked call: its answer stays as it is. */
    #[MaximumDuration(1000)]
    public function testAnotherRouteStaysAsItIs(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        AnswerLength::register($kernel->getContainer(), true);
        $this->assertTrue($this->toolCall($kernel)->headers->has('Content-Length'));

        $response = $kernel->handle(Request::create('http://127.0.0.1/elsewhere', 'POST'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** A `tools/call` of the `echo` tool in a new session, handled by the kernel: the raw answer. */
    private function toolCall(TestKernel $kernel, string $path = SymfonyHttp::PATH): Response
    {
        $http = $this->http($kernel, $path);

        return $kernel->handle($http->callRequest('echo', ['msg' => 'hi', 'session_id' => 'start']));
    }
}
