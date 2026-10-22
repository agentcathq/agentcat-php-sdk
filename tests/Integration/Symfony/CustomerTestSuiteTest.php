<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\AgentCat;
use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Symfony\HeaderIdentity;
use AgentCat\Tests\Support\Symfony\SymfonyHttp;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;

/**
 * A customer's Symfony functional tests with AgentCat's bundle registered (plan E2 Review Focus 2, D31, ruling P9): a
 * kernel booted by the test, requests sent to the MCP route, and the `agentcat` configuration empty, as the Flex recipe
 * leaves it in the test environment. As a customer's `KernelBrowser` does, each request ends with the kernel's terminate
 * phase, where AgentCat's `kernel.terminate` listener runs. Each test boots its kernels, which compile a container:
 * about 0.3 s each, measured on PHP 8.3 and 8.5.
 */
final class CustomerTestSuiteTest extends SymfonyTestCase
{
    private const string PROJECT = 'proj_sssssssssssssssssssssssssss';

    /** Without a fake, AgentCat stays off under PHPUnit (D31): the listing and a call answer as without the bundle. */
    #[MaximumDuration(2000)]
    public function testWithoutAFakeTheAnswersAreTheBundlesOwn(): void
    {
        $untracked = $this->http($this->kernel(withAgentCat: false), terminate: true);
        $plain = [$untracked->listTools(), $untracked->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'])];
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $http = $this->http($this->kernel(), terminate: true);
        $tracked = [$http->listTools(), $http->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'])];

        $this->assertEquals($plain, $tracked);
        $this->assertSame([], $logger->messages());
        $this->assertFalse(EventQueue::global()->hasWork());
    }

    /** A fake before the kernel boots records each call: the bundle tracks with the fake's client (header conflict 6). */
    #[MaximumDuration(1000)]
    public function testAFakeBeforeTheKernelBootsRecordsTheCalls(): void
    {
        $fake = AgentCat::fake();
        $http = $this->http($this->kernel(), terminate: true);
        $http->listTools();

        $http->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $http->callTool('echo', ['msg' => 'again', 'session_id' => 'start']);

        $fake->assertTrackedCount(2);
        $this->assertSame(['echo', 'echo'], array_map(static fn(Event $event): ?string => $event->resourceName, $fake->events()));
        $this->assertFalse(EventQueue::global()->hasWork()); // a fake sends nothing
    }

    /**
     * D41: the fake records what production sends, the HTTP request of each call included (header conflict 5): an
     * `identify` service reads the call's `X-User` header, and the event carries the request's headers and URL.
     */
    #[MaximumDuration(2000)]
    public function testAFakeRecordsTheRequestOfEachCallAsProductionSendsIt(): void
    {
        $hook = ['identify' => 'app.identify'];
        $services = ['app.identify' => HeaderIdentity::class];
        $production = $this->http($this->kernel(agentcat: [...$this->on(self::PROJECT), ...$hook], services: $services), terminate: true);
        $this->callAsAlice($production);
        $sent = array_map(static fn(Delivery $delivery): mixed => Json::decode($delivery->body), $this->deliveries());

        $fake = AgentCat::fake();
        $faked = $this->http($this->kernel(agentcat: $hook, services: $services), terminate: true);
        $this->callAsAlice($faked);
        $recorded = $fake->events();

        $this->assertCount(1, $sent);
        $this->assertCount(1, $recorded);
        $this->assertSame('alice', JsonAssert::at($sent[0], 'identify_actor_given_id'));
        $this->assertSame('alice', $recorded[0]->identifyActorGivenId);
        $this->assertSame('alice', JsonAssert::at($recorded[0]->parameters, 'extra', 'requestInfo', 'headers', 'x-user'));
        $this->assertEquals(
            $this->withoutSession(JsonAssert::at($sent[0], 'parameters', 'extra', 'requestInfo'), $production->sessionId()),
            $this->withoutSession(JsonAssert::at($recorded[0]->parameters, 'extra', 'requestInfo'), $faked->sessionId()),
        );
    }

    /** One `tools/call` of `echo` in a session, with the header `X-User: alice`. */
    private function callAsAlice(SymfonyHttp $http): void
    {
        $http->listTools();
        $http->request('tools/call', ['name' => 'echo', 'arguments' => ['msg' => 'hi', 'session_id' => 'start']], ['X-User' => 'alice']);
    }

    /** A call's `requestInfo` without its `mcp-session-id` header, which names the session of its own kernel. */
    private function withoutSession(mixed $requestInfo, ?string $session): mixed
    {
        $headers = JsonAssert::at($requestInfo, 'headers');
        $this->assertInstanceOf(\stdClass::class, $headers);
        $this->assertNotNull($session);
        $this->assertSame($session, $headers->{'mcp-session-id'} ?? null);
        unset($headers->{'mcp-session-id'});

        return $requestInfo;
    }
}
