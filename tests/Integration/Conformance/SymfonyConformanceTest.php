<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Conformance;

use AgentCat\McpSdk\Internal\RequestReader;
use AgentCat\Options;
use AgentCat\Symfony\Internal\RequestStackContext;
use AgentCat\Tests\Integration\Support\MockCollector;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Symfony\FullPage\FullPageTools;
use AgentCat\Tests\Support\Symfony\FullPage\SecondPageOwnTool;
use AgentCat\Tests\Support\Symfony\SymfonyConformanceHarness;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use AgentCat\Tests\Support\Symfony\TestKernel;
use AgentCat\Tests\Support\WarmUp;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The symfony/mcp-bundle leg of the adapter conformance suite, and where the bundle differs from plain mcp/sdk. Each
 * configuration's container compiles once, before the first test (`setUpBeforeClass()`); each kernel of a test loads
 * it, as each request of PHP-FPM does, so a test takes about 15 ms instead of the 0.2 s of a compile.
 */
final class SymfonyConformanceTest extends SymfonyTestCase
{
    use AdapterConformance;

    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** The directory of this class's compiled containers, under the test HOME: one directory per configuration. */
    private static string $containers = '';

    /** The API URL of every configuration, a closed local port: one per class, so each configuration compiles once. */
    private static string $apiUrl = '';

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!class_exists(McpBundle::class)) {
            return; // the mcp/sdk 0.9 leg: every test skips
        }
        self::$apiUrl = MockCollector::closedPortUrl();
        WarmUp::run(static function (): void {
            $compile = new self('testConformanceListing');
            $compile->setUp(); // it fails unless HOME is the test HOME
            self::$containers = getenv('HOME') . '/symfony-conformance-' . bin2hex(random_bytes(6));
            $compiled = false;
            try {
                $compile->conformanceKernel(new Options());
                $compile->conformanceKernel(new Options(enableTracing: false));
                $compile->fullPageKernel();
                $compile->pagedKernel();
                $compiled = true;
            } finally {
                $compile->tearDown();
                if (!$compiled) {
                    FileTree::remove(self::$containers); // PHPUnit runs no tearDownAfterClass() after this method fails
                }
            }
        });
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        FileTree::remove(self::$containers);
        parent::tearDownAfterClass();
    }

    /**
     * Library difference: the bundle serves mcp/sdk, so a handshake-era call has mcp/sdk's transport session (the
     * `Mcp-Session-Id`) in `extra`, never as the event's session (ac-02-46), as on the mcp/sdk leg. Unlike plain
     * mcp/sdk, whose adapter reads the SAPI, the bundle gives the adapter the `RequestStack` (plan E2 header conflict
     * 5): a request in this process has its `requestInfo`.
     */
    #[Group('ac-02-43')]
    #[Group('ac-02-46')]
    public function testExtraHasTheTransportSessionOfTheHandshakeAndTheRequestOfTheRequestStack(): void
    {
        $harness = $this->conformance();
        $this->assertInstanceOf(SymfonyConformanceHarness::class, $harness);
        $harness->listTools();
        $fresh = $this->conformance(fresh: true);

        $harness->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $fresh->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $event = $this->onlyEvent($harness);
        $extra = JsonAssert::at($event->parameters, 'extra');
        $this->assertSame(['sessionId', 'requestId', 'requestInfo'], array_keys((array) $extra));
        $session = $harness->sessionId();
        $this->assertIsString($session);
        $this->assertSame([$session, 'http://127.0.0.1/mcp/conformance', $session], [JsonAssert::at($extra, 'sessionId'), JsonAssert::at($extra, 'requestInfo', 'url'), JsonAssert::at($extra, 'requestInfo', 'headers', 'mcp-session-id')]);
        $this->assertSame(self::SESSION, $event->sessionId);
        $modern = $this->onlyEvent($fresh);
        $extra = JsonAssert::at($modern->parameters, 'extra');
        $this->assertSame(['requestId', '_meta', 'requestInfo'], array_keys((array) $extra));
        $this->assertSame(['http://127.0.0.1/mcp/conformance', 'tools/call'], [JsonAssert::at($extra, 'requestInfo', 'url'), JsonAssert::at($extra, 'requestInfo', 'headers', 'mcp-method')]);
    }

    #[\Override]
    protected function conformance(Options $options = new Options(), bool $fresh = false): ConformanceHarness
    {
        return SymfonyConformanceHarness::of(fn(): TestKernel => $this->conformanceKernel($options), $fresh);
    }

    #[\Override]
    protected function reusedObjectId(): ConformanceHarness
    {
        return SymfonyConformanceHarness::reusedObjectId($this->config(new Options()), self::$containers . '/agentcat.log');
    }

    #[\Override]
    protected function fullPage(): ConformanceHarness
    {
        return SymfonyConformanceHarness::of($this->fullPageKernel(...));
    }

    #[\Override]
    protected function paged(): ConformanceHarness
    {
        return SymfonyConformanceHarness::of($this->pagedKernel(...));
    }

    /** The adapter's reader with the bundle's request context: a `RequestStack` whose main request has these values. */
    #[\Override]
    protected function requestInfoOf(array $server): \stdClass
    {
        $stack = new RequestStack();
        $stack->push(new Request(server: $server));
        $reader = new RequestReader(RequestStackContext::of($stack));
        $request = (new CallToolRequest('echo', ['msg' => 'hi', 'session_id' => self::SESSION]))->withId(1);

        $info = JsonAssert::at($reader->read($request, new Session(new InMemorySessionStore()))->extra, 'requestInfo');
        $this->assertInstanceOf(\stdClass::class, $info);

        return $info;
    }

    /** A kernel of the `conformance` server, tracked with these options. */
    private function conformanceKernel(Options $options): TestKernel
    {
        return $this->compiled($this->config($options));
    }

    /** A kernel of `TestKernel::FULL_PAGE`. */
    private function fullPageKernel(): TestKernel
    {
        return $this->compiled($this->config(new Options()), TestKernel::FULL_PAGE, [FullPageTools::class => FullPageTools::class]);
    }

    /** A kernel of `TestKernel::PAGED`. */
    private function pagedKernel(): TestKernel
    {
        return $this->compiled($this->config(new Options()), TestKernel::PAGED, [FullPageTools::class => FullPageTools::class, SecondPageOwnTool::class => SecondPageOwnTool::class]);
    }

    /**
     * A kernel whose container this class compiled for its configuration.
     *
     * @param array<string, mixed>  $agentcat
     * @param array<string, mixed>  $mcp
     * @param array<string, string> $services
     */
    private function compiled(array $agentcat, array $mcp = TestKernel::MCP, array $services = []): TestKernel
    {
        $services += SymfonyConformanceHarness::SERVICES;
        $directory = self::$containers . '/' . hash('xxh3', serialize([$agentcat, $mcp, $services]));

        return $this->kernel(agentcat: $agentcat, services: $services, mcp: $mcp, directory: $directory);
    }

    /**
     * The agentcat configuration of these options (the subset the suite varies), always on, with AgentCat's lines in
     * the harness's logger.
     *
     * @return array<string, mixed>
     */
    private function config(Options $options): array
    {
        return ['api_url' => self::$apiUrl, 'logger' => SymfonyConformanceHarness::LOGGER, 'enable_tracing' => $options->enableTracing] + $this->on(self::PROJECT);
    }
}
