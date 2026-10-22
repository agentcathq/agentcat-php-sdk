<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Symfony\Internal\AnswerLength;
use AgentCat\Symfony\Internal\TerminateListener;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use AgentCat\Tests\Support\Symfony\WithoutMcpBundleKernel;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * AgentCatBundle in a Symfony application without symfony/mcp-bundle, FrameworkBundle and AgentCatBundle only: the Flex
 * recipe registers the bundle in every application that requires agentcat/sdk, a plain mcp/sdk one too. These tests
 * need no symfony/mcp-bundle, so they run in the mcp/sdk 0.9 leg too (`tests/bin/mcp-sdk-leg.sh`), which removes it.
 */
final class WithoutMcpBundleTest extends SymfonyTestCase
{
    #[\Override]
    protected function needsTheMcpBundle(): bool
    {
        return false;
    }

    /**
     * The container compiles with no server to track, the request is answered, and `terminate()` delivers the event
     * that the controller published (D58). The D62 listener, forced on as on a SAPI that sends the answer when the
     * script ends, leaves an answer of a route that is no MCP server's as it is.
     */
    #[MaximumDuration(2000)]
    public function testTheBundleServesAnApplicationWithoutMcpServers(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $kernel = new WithoutMcpBundleKernel($this->directory() . '/kernel', $this->on(WithoutMcpBundleKernel::PROJECT));
        $this->boot($kernel);
        AnswerLength::register($kernel->getContainer(), true);
        $request = Request::create('http://127.0.0.1/hello');

        $response = $kernel->handle($request);
        $queued = EventQueue::global()->hasWork(); // the controller's event waits for the terminate phase
        $kernel->terminate($request, $response);

        $this->assertTrue($queued);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->getContent());
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame([], $kernel->getContainer()->getParameter('agentcat.servers'));
        $responseListeners = $this->listeners($kernel, KernelEvents::RESPONSE);
        $this->assertInstanceOf(AnswerLength::class, end($responseListeners));
        $terminateListeners = $this->listeners($kernel, KernelEvents::TERMINATE);
        $this->assertInstanceOf(TerminateListener::class, end($terminateListeners));
        $events = array_map(static fn(Delivery $delivery): mixed => Json::decode($delivery->body), $this->transport->deliveries);
        $this->assertSame([[Constants::CUSTOM_EVENT_TYPE, WithoutMcpBundleKernel::SESSION]], array_map(static fn(mixed $event): array => [JsonAssert::at($event, 'event_type'), JsonAssert::at($event, 'session_id')], $events));
        $this->assertFalse(EventQueue::global()->hasWork());
        $this->assertSame(["Published custom event for session ses_hello with type 'agentcat:custom'"], $logger->messages());
        $this->assertDirectoryDoesNotExist($kernel->getLogDir()); // Log::setLogger() chose the logger, and no server was tracked
    }
}
