<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Symfony;

use AgentCat\AgentCat;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Diagnostics\Diagnostics;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Symfony\Internal\AnswerLength;
use AgentCat\Symfony\Internal\ServerTracker;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * D62: the length of a tracked symfony/mcp-bundle server's plain answer, on a SAPI that sends a response with the
 * script's end. The tracker tracks a server `main` here; symfony/mcp-bundle routes it to the controller
 * `mcp.server.main.controller::handle`.
 */
#[Group('ac-03-1')]
final class AnswerLengthTest extends TestCase
{
    private const string ANSWER = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"café"}]}}';

    private const string MAIN = 'mcp.server.main.controller::handle';

    #[\Override]
    protected function setUp(): void
    {
        EventQueue::setGlobal(new EventQueue(transport: new RecordingTransport()));
        Log::setLogger(new MemoryLogger());
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
        Diagnostics::reset();
        EventQueue::setGlobal(null);
        AgentCat::setClient(null);
        TrackedServers::resetIncomplete();
        TrackedServers::resetSkipped();
    }

    public function testATrackedServersPlainAnswerGetsItsLengthInBytes(): void
    {
        $response = new Response(self::ANSWER, 200, ['Content-Type' => 'application/json']);

        ($this->listener())($this->event(self::MAIN, $response));

        $this->assertSame('78', $response->headers->get('Content-Length'), 'bytes, not characters: é is two');
    }

    /** @return iterable<string, array{mixed}> the `_controller` of a request that no tracked server answers */
    public static function otherControllers(): iterable
    {
        yield 'no controller' => [null];
        yield 'the application\'s controller' => ['App\Controller\HomeController::index'];
        yield 'a server AgentCat does not track (excluded, or AgentCat off)' => ['mcp.server.other.controller::handle'];
        yield 'another method of the controller' => ['mcp.server.main.controller::handleMore'];
        yield 'a service with a longer ID' => ['app.mcp.server.main.controller::handle'];
        yield 'a callable that is no string' => [[new \stdClass(), 'handle']];
    }

    #[DataProvider('otherControllers')]
    public function testAnAnswerOfAnotherControllerStaysAsItIs(mixed $controller): void
    {
        $response = new Response(self::ANSWER);

        ($this->listener())($this->event($controller, $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** A sub-request's answer is not what the client gets: only the main request's answer gets a length. */
    public function testASubRequestsAnswerStaysAsItIs(): void
    {
        $response = new Response(self::ANSWER);

        ($this->listener())($this->event(self::MAIN, $response, HttpKernelInterface::SUB_REQUEST));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** The answer's headers reach the rule of `ContentLength`: a length of its own stays. */
    public function testAnAnswerWithItsOwnLengthKeepsIt(): void
    {
        $response = new Response(self::ANSWER, 200, ['Content-Length' => '7']);

        ($this->listener())($this->event(self::MAIN, $response));

        $this->assertSame('7', $response->headers->get('Content-Length'));
    }

    /** The answer's status reaches the rule of `ContentLength`: mcp/sdk answers OPTIONS with a 204, which has no body. */
    public function testAnAnswerWithoutABodyGetsNoLength(): void
    {
        $response = new Response('', 204);

        ($this->listener())($this->event(self::MAIN, $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** Headers that went out already (stray output without a buffer) cannot carry a length any more. */
    public function testAnAnswerAfterTheHeadersWentOutStaysAsItIs(): void
    {
        $response = new Response(self::ANSWER);

        ($this->listener(static fn(): bool => true))($this->event(self::MAIN, $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** D32: the listener runs inside Symfony's HTTP kernel, so nothing it meets may break the request. */
    public function testAResponseThatFailsLeavesTheRequestAlone(): void
    {
        $response = new class (self::ANSWER) extends Response {
            #[\Override]
            public function getContent(): string|false
            {
                throw new \RuntimeException('no content');
            }
        };

        ($this->listener())($this->event(self::MAIN, $response));

        $this->assertFalse($response->headers->has('Content-Length'));
    }

    public function testWhereResponsesEndWithTheScriptTheListenerRunsLastOfKernelResponse(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(KernelEvents::RESPONSE, static function (): void {}, -2047);
        $listener = $this->listener();

        AnswerLength::register($this->container($dispatcher, $listener), true);

        $listeners = $dispatcher->getListeners(KernelEvents::RESPONSE);
        $this->assertCount(2, $listeners);
        $this->assertSame($listener, end($listeners));
        $this->assertSame(AnswerLength::PRIORITY, $dispatcher->getListenerPriority(KernelEvents::RESPONSE, $listener));
    }

    /** PHP-FPM, LiteSpeed, FrankenPHP and the command line (Symfony Runtime workers) send a response before the drain. */
    public function testElsewhereNoListenerIsAdded(): void
    {
        $dispatcher = new EventDispatcher();

        AnswerLength::register($this->container($dispatcher, $this->listener()), false);
        AnswerLength::register($this->container($dispatcher, $this->listener())); // PHPUnit runs on the cli SAPI

        $this->assertSame([], $dispatcher->getListeners(KernelEvents::RESPONSE));
    }

    /** D32: `AgentCatBundle::boot()` registers it, and a container without the services it needs leaves the kernel alone. */
    public function testAContainerWithoutTheServicesAddsNothingAndNeverThrows(): void
    {
        $dispatcher = new EventDispatcher();
        $missingListener = new Container();
        $missingListener->set('event_dispatcher', $dispatcher);
        $otherListener = $this->container($dispatcher, new \stdClass());

        AnswerLength::register(new Container(), true);
        AnswerLength::register($missingListener, true);
        AnswerLength::register($otherListener, true);

        $this->assertSame([], $dispatcher->getListeners(KernelEvents::RESPONSE));
    }

    /** @param (\Closure(): bool)|null $headersSent */
    private function listener(?\Closure $headersSent = null): AnswerLength
    {
        $tracker = new ServerTracker(static fn(): \ArrayObject => new \ArrayObject(['enabled' => 'true', 'project_id' => 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa']), new ServiceLocator([]), null, '/nonexistent/agentcat.log');
        $tracker->track(Server::builder(), 'main');
        $this->assertTrue($tracker->tracked('main'));

        return new AnswerLength($tracker, $headersSent);
    }

    private function event(mixed $controller, Response $response, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        $request = Request::create('/mcp', 'POST');
        if ($controller !== null) {
            $request->attributes->set('_controller', $controller);
        }
        $kernel = new class implements HttpKernelInterface {
            #[\Override]
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new ResponseEvent($kernel, $request, $type, $response);
    }

    private function container(EventDispatcher $dispatcher, object $listener): Container
    {
        $container = new Container();
        $container->set('event_dispatcher', $dispatcher);
        $container->set(AnswerLength::SERVICE, $listener);

        return $container;
    }
}
