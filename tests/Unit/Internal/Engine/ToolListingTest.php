<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Engine\ToolListInjector;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ToolListingTest extends TestCase
{
    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private const string ECHO_TOOL = '{"name":"echo","inputSchema":{"type":"object","properties":{"msg":{"type":"string"}},"required":["msg"]}}';

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    private function server(): TrackedServer
    {
        return new TrackedServer('proj', new Options(), static fn(): array => [null, null], new Publisher(new EventQueue(transport: new RecordingTransport())));
    }

    private function tool(string $json): \stdClass
    {
        $value = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    /**
     * @param list<mixed>|null $tools
     * @return list<string>
     */
    private function names(?array $tools): array
    {
        $this->assertNotNull($tools);

        return array_map(function (mixed $tool): string {
            $this->assertInstanceOf(\stdClass::class, $tool);
            $name = $tool->name;
            $this->assertIsString($name);

            return $name;
        }, $tools);
    }

    #[Group('ac-04-28')]
    #[Group('ac-02-11')]
    public function testEmptyListingIsServedAsIs(): void
    {
        $server = $this->server();

        $this->assertNull(ToolListing::apply($server, []));
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $this->logger->messages());
        $this->assertNull($server->registry);
    }

    /** TS listWrap: `!Array.isArray(tools) || tools.length === 0`. */
    #[Group('ac-04-28')]
    public function testAListingWithoutAToolListIsServedAsIs(): void
    {
        $server = $this->server();

        $this->assertNull(ToolListing::apply($server, null));
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $this->logger->messages());
        $this->assertNull($server->registry);
    }

    public function testListingAddsGetMoreToolsAndRecordsRegistry(): void
    {
        $server = $this->server();

        $out = ToolListing::apply($server, [$this->tool(self::ECHO_TOOL)]);

        $this->assertSame(['echo', 'get_more_tools'], $this->names($out));
        $this->assertSame(['echo' => ['session_id', 'context'], 'get_more_tools' => ['session_id']], $server->registry);
    }

    public function testEachPageIsInjectedAndTheRegistryHoldsTheLastPage(): void
    {
        $server = $this->server();

        $page1 = ToolListing::apply($server, [$this->tool('{"name":"a"}')]);
        $page2 = ToolListing::apply($server, [$this->tool('{"name":"b"}')]);

        $this->assertSame(['a', 'get_more_tools'], $this->names($page1));
        $this->assertSame(['b', 'get_more_tools'], $this->names($page2));
        $registry = $server->registry;
        $this->assertNotNull($registry);
        $this->assertSame(['b', 'get_more_tools'], array_keys($registry));
    }

    public function testDegradedInjectionKeepsThePreviousRegistries(): void
    {
        $server = $this->server();
        ToolListing::apply($server, [$this->tool(self::ECHO_TOOL)]);
        $before = $server->registry;
        $hostile = new class extends \stdClass {
            public function __isset(string $name): bool
            {
                return true;
            }

            public function __get(string $name): mixed
            {
                throw new \RuntimeException('boom');
            }
        };

        $this->assertNull(ToolListing::apply($server, [$hostile]));
        $this->assertSame(
            ['Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - boom'],
            $this->logger->messages(),
        );
        $this->assertSame($before, $server->registry);
        $this->assertNotNull($before);
    }

    public function testEnsureRegistriesSkipsTheListingWhenRegistriesExist(): void
    {
        $server = $this->server();
        ToolListing::apply($server, [$this->tool('{"name":"a"}')]);
        $called = false;

        ToolListing::ensureRegistries($server, static function () use (&$called): array {
            $called = true;

            return [];
        });

        $this->assertFalse($called);
    }

    #[Group('ac-02-20')]
    public function testEnsureRegistriesRebuildsOnDemand(): void
    {
        $server = $this->server();

        ToolListing::ensureRegistries($server, fn(): array => [$this->tool(self::ECHO_TOOL)]);

        $this->assertSame(['echo' => ['session_id', 'context'], 'get_more_tools' => ['session_id']], $server->registry);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    public function testEnsureRegistriesRebuildsFromAnEmptyListing(): void
    {
        $server = $this->server();

        ToolListing::ensureRegistries($server, static fn(): array => []);

        $this->assertSame(['get_more_tools' => ['session_id']], $server->registry);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    public function testEnsureRegistriesIgnoresAMissingListing(): void
    {
        $server = $this->server();

        ToolListing::ensureRegistries($server, static fn(): ?array => null);

        $this->assertNull($server->registry);
        $this->assertSame([], $this->logger->messages());
    }

    public function testEnsureRegistriesDoesNotAdoptADegradedInjection(): void
    {
        $server = $this->server();
        $hostile = new class extends \stdClass {
            public function __isset(string $name): bool
            {
                return true;
            }

            public function __get(string $name): mixed
            {
                throw new \RuntimeException('boom');
            }
        };

        ToolListing::ensureRegistries($server, static fn(): array => [$hostile]);

        $this->assertNull($server->registry);
        $this->assertSame(
            ['Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - boom'],
            $this->logger->messages(),
        );
    }

    #[Group('ac-02-21')]
    public function testEnsureRegistriesLogsAFailureAndRetriesNextTime(): void
    {
        $server = $this->server();
        $calls = 0;
        $list = static function () use (&$calls): array {
            ++$calls;

            throw new \RuntimeException('list down');
        };

        ToolListing::ensureRegistries($server, $list);
        ToolListing::ensureRegistries($server, $list);

        $this->assertSame(2, $calls);
        $this->assertNull($server->registry);
        $this->assertSame(
            array_fill(0, 2, 'Warning: registry rebuild-on-demand failed - RuntimeException: list down'),
            $this->logger->messages(),
        );
    }
}
