<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Client;
use AgentCat\Event;
use AgentCat\Internal\ClientSeams;
use AgentCat\Internal\Engine\HttpContext;
use AgentCat\Internal\Engine\TrackedServers;
use AgentCat\Internal\Logging\Log;
use AgentCat\Symfony\Internal\RequestStackContext;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The HTTP request of an mcp/sdk call from a framework bridge instead of `$_SERVER` (plan E2 header conflict 5): a
 * worker that serves many requests in one process, where `$_SERVER` is stale, records each request's own.
 */
final class RequestContextSeamTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        TrackedServers::resetIncomplete(); // a failed track() marks the process
        Log::reset();
    }

    #[Group('ac-02-43')]
    public function testTheClientsContextReplacesTheSapiGlobals(): void
    {
        $fixture = new TrackingFixture();
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $client = new Client(ClientSeams::of($fixture->queue, static fn(): bool => true, false, static fn(): HttpContext => new HttpContext('POST', 'https://worker.test/mcp', ['x-from' => 'request-stack'])));
        $client->track($builder, TrackingFixture::PROJECT);
        $harness = $fixture->harness($builder);

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $info = JsonAssert::at($fixture->event()->parameters, 'extra', 'requestInfo');
        $this->assertSame(['x-from' => 'request-stack'], (array) JsonAssert::at($info, 'headers'));
        $this->assertSame('https://worker.test/mcp', JsonAssert::at($info, 'url'));
    }

    /** Review Focus 4: one container, many requests, as in a Symfony Runtime worker under the CLI SAPI. */
    #[Group('ac-02-43')]
    public function testEachRequestOfAWorkerCarriesItsOwnHeaders(): void
    {
        $fixture = new TrackingFixture();
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        $requests = new RequestStack();
        $client = new Client(ClientSeams::of($fixture->queue, static fn(): bool => true, false, RequestStackContext::of($requests)));
        $client->track($builder, TrackingFixture::PROJECT);
        $harness = $fixture->harness($builder);

        foreach (['ann', 'bob', 'cid'] as $user) {
            $requests->push(Request::create('/mcp', 'POST', server: ['HTTP_X_USER' => $user]));
            $harness->callTool('echo', ['msg' => $user, 'session_id' => 'start']);
            $requests->pop();
        }

        $events = $fixture->events();
        $this->assertSame(['ann', 'bob', 'cid'], array_map(static fn(Event $e): mixed => JsonAssert::at($e->parameters, 'extra', 'requestInfo', 'headers', 'x-user'), $events));
    }

    public function testWithoutAContextTheSapiGlobalsStillCount(): void
    {
        $fixture = new TrackingFixture();
        $builder = TrackingFixture::builder();
        Tools::echo($builder);
        (new Client(ClientSeams::of($fixture->queue, static fn(): bool => true, false)))->track($builder, TrackingFixture::PROJECT);
        $harness = $fixture->harness($builder);

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $extra = JsonAssert::at($fixture->event()->parameters, 'extra');
        $this->assertInstanceOf(\stdClass::class, $extra);
        $this->assertObjectNotHasProperty('requestInfo', $extra); // PHPUnit runs on the CLI
    }
}
