<?php

declare(strict_types=1);

namespace AgentCat\Tests\Contract\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\Internal\CallToolInterceptor;
use AgentCat\McpSdk\Internal\ListToolsInterceptor;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\BuilderMembers;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\NameService;
use AgentCat\Tests\Support\McpSdk\RecordingTool;
use AgentCat\Tests\Support\McpSdk\WiredTool;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use AgentCat\Tests\Support\Thrown;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Handler\Request\ListToolsHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `BuilderAccess::probe()` runs at track(), before build() fills `Builder::$parts`, so it cannot check what the
 * built parts hold. When a future mcp/sdk changes them, the request path logs one compatibility line and leaves
 * the server to the library (README §4.5 rule 4): `tools/list` and `tools/call` alike.
 */
#[Group('contract')]
final class RuntimeDriftTest extends TestCase
{
    private const string COMPATIBILITY = 'AgentCat SDK Compatibility: AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

    private MemoryLogger $logger;

    private RecordingTransport $transport;

    private EventQueue $queue;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
        $this->transport = new RecordingTransport();
        $this->queue = new EventQueue(transport: $this->transport);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testInspectReportsDriftThatProbeFinds(): void
    {
        $builder = Server::builder();
        $this->setParts($builder, []);

        $error = Thrown::by(\UnexpectedValueException::class, static fn() => McpSdkAdapter::inspect($builder));

        $this->assertSame(
            sprintf('AgentCat SDK compatibility error: mcp/sdk %s internals changed (ErrorException: Undefined array key "requestHandlers"). %s', McpSdkAdapter::version(), McpSdkAdapter::SUPPORT_MATRIX),
            $error->getMessage(),
        );
        $this->assertInstanceOf(\ErrorException::class, $error->getPrevious());
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    public function testSignalsLeaveOutBuiltWhenPartsCannotBeRead(): void
    {
        $builder = Server::builder();
        BuilderMembers::uninitialize('parts')($builder);

        $this->assertSame(['mcpSdkBuilder'], McpSdkAdapter::signals($builder));
    }

    /**
     * A read of `$parts` that warns, as a read of a member that a new mcp/sdk removed does, must not reach PHP's
     * error handler: it prints to STDOUT, which is the protocol stream of a stdio server. PHP cannot remove a declared
     * property, so a PHP 8.4 lazy object stands in for that read: reading `$parts` runs its initializer, which warns.
     */
    #[RequiresPhp('>= 8.4.0')]
    public function testSignalsLetNoWarningOut(): void
    {
        // ReflectionClass::newLazyGhost() is PHP 8.4+, and PHPStan checks this file against PHP 8.3 too.
        $builder = (new \ReflectionMethod(\ReflectionClass::class, 'newLazyGhost'))->invoke(new \ReflectionClass(Builder::class), static function (): void {
            trigger_error('Undefined property: Mcp\Server\Builder::$parts', E_USER_WARNING);
        });
        $this->assertInstanceOf(Builder::class, $builder);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $signals = McpSdkAdapter::signals($builder);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['mcpSdkBuilder'], $signals);
        $this->assertSame([], $warnings);
    }

    public function testTheLoggerIsUnknownWhenItsMemberCannotBeRead(): void
    {
        $builder = Server::builder()->setLogger(new NullLogger());
        BuilderMembers::uninitialize('logger')($builder);

        $this->assertNull(McpSdkAdapter::logger($builder));
    }

    /** @return iterable<string, array{\Closure(array<array-key, mixed>): ?array<array-key, mixed>}> */
    public static function handlerDrift(): iterable
    {
        yield 'parts lost requestHandlers' => [static fn(array $parts): array => array_diff_key($parts, ['requestHandlers' => true])];
        yield 'requestHandlers retyped' => [static fn(array $parts): array => ['requestHandlers' => 'handlers'] + $parts];
        yield 'parts no longer kept' => [static fn(): ?array => null];
        yield 'AgentCat handlers not in the list' => [static fn(array $parts): array => self::withHandlers($parts, static fn(array $handlers): array => array_values(array_filter(
            $handlers,
            static fn(mixed $handler): bool => !self::ours($handler),
        )))];
        yield 'library handlers no longer after AgentCat' => [static fn(array $parts): array => self::withHandlers($parts, static fn(array $handlers): array => array_values(array_filter(
            $handlers,
            self::ours(...),
        )))];
        yield 'entries are not handler objects' => [static fn(array $parts): array => self::withHandlers($parts, static fn(array $handlers): array => array_map(
            static fn(mixed $handler): mixed => self::ours($handler) || !is_object($handler) ? $handler : $handler::class,
            $handlers,
        ))];
    }

    /** @param \Closure(array<array-key, mixed>): ?array<array-key, mixed> $drift */
    #[DataProvider('handlerDrift')]
    public function testChangedHandlersLeaveTheListingToTheLibrary(\Closure $drift): void
    {
        $twin = new HttpHarness($this->builder()->build());
        $twin->initialize();
        $expected = $twin->listTools();
        $builder = $this->builder();
        [$server, $harness] = $this->tracked($builder);
        $this->setParts($builder, $drift($this->parts($builder)));
        $harness->initialize();

        $first = $harness->listTools();
        $second = $harness->listTools();

        $this->assertEquals($expected, $first);
        $this->assertEquals($expected, $second);
        $this->assertNull($server->registry);
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    /** @param \Closure(array<array-key, mixed>): ?array<array-key, mixed> $drift */
    #[DataProvider('handlerDrift')]
    public function testChangedHandlersLeaveTheCallToTheLibrary(\Closure $drift): void
    {
        $twin = new HttpHarness($this->builder()->build());
        $twin->initialize();
        $expected = $twin->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $builder = $this->builder();
        [, $harness] = $this->tracked($builder);
        $this->setParts($builder, $drift($this->parts($builder)));
        $harness->initialize();

        $first = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $second = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertEquals($expected, $first);
        $this->assertEquals($expected->result ?? null, $second->result ?? null);
        $this->assertSame(['queueLength' => 0, 'activeRequests' => 0], $this->queue->stats(), 'no event');
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    public function testACallWithoutAListHandlerAfterAgentCatStripsHeuristically(): void
    {
        $builder = $this->builder();
        [$server, $harness] = $this->tracked($builder);
        $this->setParts($builder, self::withHandlers($this->parts($builder), static fn(array $handlers): array => array_values(array_filter(
            $handlers,
            static fn(mixed $handler): bool => !$handler instanceof ListToolsHandler,
        ))));
        $harness->initialize();

        $answer = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertObjectHasProperty('result', $answer);
        $this->assertNull($server->registry, 'nothing to rebuild from');
        $this->assertSame(['queueLength' => 1, 'activeRequests' => 0], $this->queue->stats(), 'the call is still traced');
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    public function testAnUnreadableContainerFallsBackToTheLibraryDefault(): void
    {
        $builder = $this->builder()->addTool(WiredTool::class, name: 'wired');
        [, $harness] = $this->tracked($builder);
        BuilderMembers::uninitialize('container')($builder);
        $harness->initialize();
        $harness->listTools();

        $first = $harness->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $second = $harness->callTool('wired', ['name' => 'Ada', 'session_id' => 'start']);

        $this->assertSame('ok', JsonAssert::at(HttpHarness::result($first), 'content', 1, 'text'));
        $this->assertSame('wired Ada (' . NameService::class . ')', JsonAssert::at(HttpHarness::result($second), 'content', 1, 'text'), 'autowired like the library default');
        $this->assertTrue($this->queue->flush());
        $this->assertCount(2, $this->transport->deliveries, 'both calls are traced');
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    public function testTheHandlerListReadAtTheFirstRequestIsKept(): void
    {
        $builder = $this->builder();
        [, $harness] = $this->tracked($builder);
        $harness->initialize();
        $first = $harness->listTools();

        $this->setParts($builder, null);
        $second = $harness->listTools();

        $this->assertEquals($first, $second);
        $this->assertCount(2, $this->list($second->tools ?? null), 'echo and get_more_tools');
        $this->assertSame([], $this->logger->messages());
    }

    public function testTwoDriftsOnOneBuilderLogOneLine(): void
    {
        $builder = $this->builder();
        [$server, $harness] = $this->tracked($builder);
        $this->setParts($builder, array_diff_key($this->parts($builder), ['requestHandlers' => true, 'configuration' => true]));
        $harness->initialize();

        $harness->listTools();

        $this->assertSame([null, null], $server->serverInfo());
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    public function testEveryBuilderReportsItsOwnDrift(): void
    {
        foreach ([$this->builder(), $this->builder()] as $builder) {
            [, $harness] = $this->tracked($builder);
            $this->setParts($builder, null);
            $harness->initialize();
            $harness->listTools();
        }

        $this->assertSame([self::COMPATIBILITY, self::COMPATIBILITY], $this->logger->messages());
    }

    /** @return iterable<string, array{\Closure(array<array-key, mixed>): array<array-key, mixed>}> */
    public static function configurationDrift(): iterable
    {
        yield 'parts lost configuration' => [static fn(array $parts): array => array_diff_key($parts, ['configuration' => true])];
        yield 'configuration retyped' => [static fn(array $parts): array => ['configuration' => 'configuration'] + $parts];
    }

    /** @param \Closure(array<array-key, mixed>): array<array-key, mixed> $drift */
    #[DataProvider('configurationDrift')]
    public function testUnreadableServerInfoIsUnknown(\Closure $drift): void
    {
        $builder = $this->builder();
        $info = McpSdkAdapter::serverInfo($builder);
        $builder->build();
        $this->setParts($builder, $drift($this->parts($builder)));

        $this->assertSame([null, null], $info());
        $this->assertSame([null, null], $info());
        $this->assertSame([self::COMPATIBILITY], $this->logger->messages());
    }

    /**
     * Tracks the Builder and builds it. The harness is not initialized yet.
     *
     * @return array{TrackedServer, HttpHarness}
     */
    private function tracked(Builder $builder): array
    {
        $publisher = new Publisher($this->queue);
        $server = new TrackedServer('proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa', new Options(), McpSdkAdapter::serverInfo($builder), $publisher);
        McpSdkAdapter::install($builder, $server, $publisher, new CallInterceptor($publisher));

        return [$server, new HttpHarness($builder->build())];
    }

    /**
     * @param array<array-key, mixed> $parts
     * @param \Closure(array<array-key, mixed>): array<array-key, mixed> $change
     *
     * @return array<array-key, mixed>
     */
    private static function withHandlers(array $parts, \Closure $change): array
    {
        $handlers = $parts['requestHandlers'] ?? null;
        self::assertIsArray($handlers);

        return ['requestHandlers' => $change($handlers)] + $parts;
    }

    private static function ours(mixed $handler): bool
    {
        return $handler instanceof ListToolsInterceptor || $handler instanceof CallToolInterceptor;
    }

    private function builder(): Builder
    {
        return Server::builder()->setServerInfo('drift', '1.0.0')->add(
            new Tool(name: 'echo', title: null, inputSchema: ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg']], description: null, annotations: null),
            new RecordingTool(),
        );
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        $this->assertIsList($value);

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function parts(Builder $builder): array
    {
        $parts = (new \ReflectionProperty(Builder::class, 'parts'))->getValue($builder);
        $this->assertIsArray($parts);

        return $parts;
    }

    /** @param array<array-key, mixed>|null $parts */
    private function setParts(Builder $builder, ?array $parts): void
    {
        (new \ReflectionProperty(Builder::class, 'parts'))->setValue($builder, $parts);
    }
}
