<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\AgentCat;
use AgentCat\Event;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\MemoryLogger;
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
}
