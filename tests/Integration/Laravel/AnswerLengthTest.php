<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\Internal\Delivery\FinishRequest;
use AgentCat\Laravel\Internal\AnswerLength;
use AgentCat\Tests\Support\Laravel\BridgeTestCase;
use AgentCat\Tests\Support\LaravelMcp\HeaderIdentityServer;
use AgentCat\Tests\Support\LaravelMcp\LaravelHarness;
use AgentCat\Tests\Support\LaravelMcp\LaravelTrackingFixture;
use AgentCat\Tests\Support\LaravelMcp\StreamingServer;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Facades\Mcp;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Response;

/**
 * D59 through Laravel's HTTP kernel: on a SAPI that sends a response only when the script ends, the plain answer of a
 * tracked laravel/mcp server gets its `Content-Length`. The tests run under the CLI, so each one that needs the
 * listener registers it as `AgentCatServiceProvider` does on such a SAPI.
 */
#[Group('ac-03-1')]
final class AnswerLengthTest extends BridgeTestCase
{
    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);
        config(['agentcat.enabled' => true, 'agentcat.project_id' => LaravelTrackingFixture::PROJECT]);
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes(mixed $router): void
    {
        Mcp::web('/mcp/test', HeaderIdentityServer::class);
        Mcp::web('/mcp/stream', StreamingServer::class);
        $router->post('/plain', static fn(): string => 'plain');
    }

    /** Under the CLI the provider adds no listener: the SAPI has no request that ends with the script. */
    public function testThisSapiGetsNoListener(): void
    {
        $this->assertFalse(FinishRequest::responseEndsWithTheScript(PHP_SAPI, $_SERVER, function_exists(...)));

        $response = $this->toolCall('echo');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    public function testATrackedServersAnswerGetsItsLength(): void
    {
        AnswerLength::register($this->application(), true);

        $response = $this->toolCall('echo');

        $response->assertOk();
        $content = $response->baseResponse->getContent();
        $this->assertIsString($content);
        $this->assertSame((string) strlen($content), $response->headers->get('Content-Length'));
        $this->assertCount(1, $this->deliveries(), 'the server was tracked');
    }

    public function testAnExcludedServersAnswerStaysAsItIs(): void
    {
        AnswerLength::register($this->application(), true);
        config(['agentcat.exclude' => [HeaderIdentityServer::class]]);

        $response = $this->toolCall('echo');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame([], $this->deliveries());
    }

    public function testAnAnswerWhileAgentCatIsOffStaysAsItIs(): void
    {
        AnswerLength::register($this->application(), true);
        config(['agentcat.enabled' => false]);

        $response = $this->toolCall('echo');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertSame([], $this->deliveries());
    }

    #[MaximumDuration(1000)]
    public function testAStreamedAnswerStaysAsItIs(): void
    {
        AnswerLength::register($this->application(), true);

        $response = $this->toolCall('stream', '/mcp/stream');

        $response->assertOk();
        $this->assertStringContainsString('"text":"done"', $response->streamedContent());
        $this->assertFalse($response->headers->has('Content-Length'));
        $this->assertCount(1, $this->deliveries(), 'the server was tracked');
    }

    public function testAnotherRouteStaysAsItIs(): void
    {
        AnswerLength::register($this->application(), true);

        $response = $this->post('/plain');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Content-Length'));
    }

    /** The mark lives on the request: in one application, like an Octane worker, the next request does not inherit it. */
    public function testTheNextRequestOfTheApplicationIsNotMarked(): void
    {
        AnswerLength::register($this->application(), true);

        $this->assertTrue($this->toolCall('echo')->headers->has('Content-Length'));
        $this->assertFalse($this->post('/plain')->headers->has('Content-Length'));
    }

    /**
     * A 2026-07-28 `tools/call`, with the headers laravel/mcp's `ValidateMcpHeaders` requires.
     *
     * @return TestResponse<Response>
     */
    private function toolCall(string $tool, string $uri = '/mcp/test'): TestResponse
    {
        return $this->postJson($uri, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $tool === 'echo' ? ['msg' => 'hi', 'session_id' => 'start'] : ['session_id' => 'start'], '_meta' => LaravelHarness::meta([], ['name' => 'modern-client', 'version' => '2.0.0'])],
        ], ['MCP-Protocol-Version' => LaravelHarness::MODERN_PROTOCOL_VERSION, 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool]);
    }

    private function application(): Application
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return $this->app;
    }
}
