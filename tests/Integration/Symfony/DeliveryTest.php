<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Delivery\RequestEndWait;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Symfony\Internal\TerminateListener;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Symfony\HeaderIdentity;
use AgentCat\Tests\Support\Symfony\SymfonyHttp;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Delivery after each request of a Symfony application (plan E2 header conflict 7, D58): `TerminateListener` waits on
 * `kernel.terminate`. PHPUnit runs on the `cli` SAPI, so the listener waits as in a Symfony Runtime worker, at most
 * `RequestEndWait::WORKER_SECONDS`. The global queue records its deliveries, unless a test replaces it. Each test
 * boots its own kernel, which compiles a container: 0.2 to 0.5 s measured on PHP 8.3 and 8.5.
 */
final class DeliveryTest extends SymfonyTestCase
{
    private const string PROJECT = 'proj_ddddddddddddddddddddddddddd';

    private static ?MockCollector $collector = null;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$collector = MockCollector::start();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$collector?->stop();
        self::$collector = null;
    }

    #[Group('ac-03-12')]
    #[Group('ac-02-48')]
    #[MaximumDuration(1000)]
    public function testKernelTerminateDeliversTheRequestsEvents(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        $http = $this->http($kernel);
        $http->listTools();
        $request = $http->callRequest('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $response = $kernel->handle($request);
        $this->assertCount(0, $this->transport->deliveries); // queued after the call's own pump: nothing sent yet
        $kernel->terminate($request, $response);

        $this->assertCount(1, $this->transport->deliveries);
    }

    /** Priority -2048: after Symfony's own `kernel.terminate` listeners and the application's. */
    #[MaximumDuration(1000)]
    public function testTheListenerRunsLast(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $listeners = $dispatcher->getListeners(KernelEvents::TERMINATE); // sorted: the last has the lowest priority
        $last = end($listeners);

        $this->assertIsCallable($last);
        $this->assertInstanceOf(TerminateListener::class, is_array($last) ? $last[0] : $last);
        $this->assertSame(-2048, $dispatcher->getListenerPriority(KernelEvents::TERMINATE, $last));
    }

    #[Group('ac-03-1')]
    #[MaximumDuration(1000)]
    public function testAWorkerWaitsAtMostTheCapAndTheNextRequestDeliversTheRest(): void
    {
        $kernel = $this->kernel(agentcat: $this->on(self::PROJECT));
        $http = $this->http($kernel);
        $http->listTools();
        $this->transport->complete = false; // an API that does not answer within the request; the clock is simulated
        $request = $http->callRequest('echo', ['msg' => 'one', 'session_id' => 'start']);
        $response = $kernel->handle($request);
        $before = $this->transport->now();

        $kernel->terminate($request, $response);

        $this->assertSame(RequestEndWait::WORKER_SECONDS, $this->transport->now() - $before); // the cap (D58), not the 5 s budget
        $this->assertSame(1, $this->transport->inFlight());
        $this->transport->complete = true;
        $http->callTool('echo', ['msg' => 'two', 'session_id' => 'start']); // the next request's pump() finishes the first delivery

        $this->assertSame(0, $this->transport->inFlight());
    }

    /**
     * The real curl transport. The collector answers after 4 s: a listener that waited for the 5 s shutdown budget
     * would hold the terminate phase at least that long, and the worker's stop still has time to deliver (ruling P16).
     */
    #[MaximumDuration(12000)]
    public function testWithASlowApiTerminateReturnsAfterAboutOneSecondAndTheWorkerStopDelivers(): void
    {
        $collector = self::$collector;
        $this->assertInstanceOf(MockCollector::class, $collector);
        $scenario = $collector->scenario([['status' => 202, 'delayMs' => 4000]]);
        EventQueue::setGlobal(new EventQueue()); // the real curl transport, before the kernel builds a server
        $kernel = $this->kernel(agentcat: [...$this->on(self::PROJECT), 'api_url' => $scenario]);
        $http = $this->http($kernel);
        $http->listTools();
        $request = $http->callRequest('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $response = $kernel->handle($request);
        $started = microtime(true);

        $kernel->terminate($request, $response);

        $elapsed = microtime(true) - $started;
        $this->assertGreaterThanOrEqual(RequestEndWait::WORKER_SECONDS - 0.1, $elapsed); // it waited for the slow API ...
        $this->assertLessThan(RequestEndWait::WORKER_SECONDS + 1.5, $elapsed); // ... for the cap, not for the 5 s shutdown budget
        $this->assertSame([], $collector->requests($scenario)); // the collector records a request when it answers
        EventQueue::global()->finalShutdown(); // what a worker's shutdown function does when the worker stops

        $this->assertCount(1, $collector->requests($scenario)); // the shutdown waited for the answer inside its 5 s
    }

    /**
     * Ruling P10 (plan E1 final ruling I1, D04): one kernel, requests with different `X-User`, and an `identify` service
     * that reads the `request_stack`, while the AgentCat API backs up. The first five deliveries stay in flight, so the
     * later events wait for a free slot past their own request. Each hook still runs before its request's terminate
     * phase ends, so it never reads a later request: it finds the stack empty, as `HttpKernel::handle()` pops the request
     * before `kernel.terminate`. Hooks read the `ToolCall`, which carries each request's own headers.
     */
    #[Group('ac-03-1')]
    #[MaximumDuration(2000)]
    public function testAHookThatReadsTheRequestStackNeverSeesALaterRequest(): void
    {
        $kernel = $this->kernel(agentcat: [...$this->on(self::PROJECT), 'identify' => 'app.identify'], services: ['app.identify' => HeaderIdentity::class]);
        $identity = $kernel->getContainer()->get('app.identify');
        $this->assertInstanceOf(HeaderIdentity::class, $identity);
        $http = $this->http($kernel);
        $http->listTools();
        $this->transport->complete = false; // a slow AgentCat API: the deliveries stay in flight; the clock is simulated
        $users = ['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8'];

        foreach ($users as $done => $user) {
            $request = $http->callRequest('echo', ['msg' => $user, 'session_id' => 'start'], headers: ['X-User' => $user]);
            $kernel->terminate($request, $kernel->handle($request));

            $this->assertCount($done + 1, $identity->seen, 'the hook of ' . $user . ' ran in its own request');
        }
        $this->assertSame(EventQueue::CONCURRENCY, $this->transport->inFlight()); // u6 to u8 wait for a free slot
        $this->transport->complete = true;

        $bodies = array_map(static fn(Delivery $d): mixed => Json::decode($d->body), $this->deliveries());
        $this->assertSame($users, array_map(static fn(mixed $body): mixed => JsonAssert::at($body, 'parameters', 'request', 'params', 'arguments', 'msg'), $bodies));
        $this->assertSame($users, array_map(static fn(mixed $body): mixed => JsonAssert::at($body, 'identify_actor_given_id'), $bodies));
        $this->assertSame(array_map(static fn(string $user): array => [$user, null], $users), $identity->seen);
    }

    /**
     * An SSE answer runs the rest of the call in the `StreamedResponse` callback, after `HttpKernel::handle()` popped the
     * request; HttpKernel pushes it again around the callback, so the event carries its own request's headers.
     */
    #[Group('ac-02-43')]
    #[MaximumDuration(1000)]
    public function testAStreamedAnswersEventCarriesItsOwnRequestsHeaders(): void
    {
        $kernel = $this->streamingKernel($this->on(self::PROJECT));
        $http = $this->http($kernel);
        $http->listTools();
        $request = $http->callRequest('progress', ['msg' => 'hi', 'session_id' => 'start'], ['progressToken' => 'p1'], ['X-User' => 'streamed']);
        $response = $kernel->handle($request);
        $this->assertInstanceOf(StreamedResponse::class, $response);

        $body = SymfonyHttp::body($response); // the client reads the stream: the tool finishes now
        $kernel->terminate($request, $response);

        $this->assertStringContainsString('"message":"half way"', $body); // the progress notification
        $this->assertStringContainsString('done: hi', $body);
        $this->assertCount(1, $this->transport->deliveries);
        $headers = JsonAssert::at(Json::decode($this->transport->deliveries[0]->body), 'parameters', 'extra', 'requestInfo', 'headers');
        $this->assertSame('streamed', JsonAssert::at($headers, 'x-user'));
    }
}
