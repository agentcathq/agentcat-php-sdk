<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\ToolListing;
use AgentCat\Internal\Engine\ToolListInjector;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Handles\ArgumentStripper;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Options;
use AgentCat\Tests\Support\CallContexts;
use AgentCat\Tests\Support\FakeCallSeam;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ToolListingTest extends TestCase
{
    private const string REBUILT = 'Rebuilt injection registries on demand (tools/call before tools/list on this instance)';

    private const string STOPPED = 'Warning: registry rebuild-on-demand stopped after page ';

    private const string ECHO_TOOL = '{"name":"echo","inputSchema":{"type":"object","properties":{"msg":{"type":"string"}},"required":["msg"]}}';

    private const string OWN_TOOL = '{"name":"own","inputSchema":{"type":"object","properties":{"session_id":{"type":"string"}},"required":["session_id"]}}';

    /** The heuristic strip for a tool no listing has named. */
    private const array HEURISTIC = ['session_id', 'agent_id', 'context'];

    /** What a tool without an own parameter loses: AgentCat's two injected parameters (agent tracking is off). */
    private const array INJECTED = ['session_id', 'context'];

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

    private function server(Options $options = new Options()): TrackedServer
    {
        return new TrackedServer('proj', $options, static fn(): array => [null, null], new Publisher(new EventQueue(transport: new RecordingTransport())));
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

    /**
     * A library listing of these pages by the cursor that asks for them ('' for the first): tools and next cursor, null
     * for an answer without a tool list, or what listing throws. Records each cursor asked for in `$asked`.
     *
     * @param array<string, array{list<mixed>, ?string}|\Throwable|null> $pages
     * @param \ArrayObject<int, ?string> $asked
     * @return \Closure(?string): (array{list<mixed>, ?string}|null)
     */
    private function pages(array $pages, \ArrayObject $asked = new \ArrayObject()): \Closure
    {
        return static function (?string $cursor) use ($pages, $asked): ?array {
            $asked->append($cursor);
            $page = $pages[$cursor ?? ''] ?? null;
            if ($page instanceof \Throwable) {
                throw $page;
            }

            return $page;
        };
    }

    /**
     * An endless listing: page n holds tool `t<n>` and the cursor of page n+1. Records each cursor asked for.
     *
     * @param \ArrayObject<int, ?string> $asked
     * @return \Closure(?string): array{list<mixed>, string}
     */
    private function endless(\ArrayObject $asked): \Closure
    {
        return static function (?string $cursor) use ($asked): array {
            $asked->append($cursor);
            $n = (int) ($cursor ?? '0');

            return [[(object) ['name' => 't' . $n]], (string) ($n + 1)];
        };
    }

    /**
     * laravel/mcp's pages of 15 for 16 tools: `t01` to `t15` on page 1, then `own` (its own `session_id`) on page 2.
     *
     * @return array<string, array{list<mixed>, ?string}>
     */
    private function sixteenTools(): array
    {
        return [
            '' => [array_map(fn(int $i): \stdClass => $this->tool(sprintf('{"name":"t%02d"}', $i)), range(1, 15)), 'p2'],
            'p2' => [[$this->tool(self::OWN_TOOL)], null],
        ];
    }

    /** @param array<array-key, mixed> $arguments */
    private function seam(string $name, array $arguments = []): FakeCallSeam
    {
        return new FakeCallSeam($name, CallContexts::arguments($arguments), CallContexts::sample($name, $arguments));
    }

    private function intercept(TrackedServer $server, FakeCallSeam $seam): \stdClass
    {
        return (new CallInterceptor(new Publisher(new EventQueue(transport: new RecordingTransport()))))->intercept($server, $seam);
    }

    /** @return list<array-key> */
    private function registered(TrackedServer $server): array
    {
        $registry = $server->registry;
        $this->assertNotNull($registry);

        return array_keys($registry);
    }

    #[Group('ac-04-28')]
    #[Group('ac-02-11')]
    public function testEmptyListingIsServedAsIs(): void
    {
        $server = $this->server();

        $this->assertNull(ToolListing::apply($server, [], lastPage: true));
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $this->logger->messages());
        $this->assertNull($server->registry);
    }

    /** TS listWrap: `!Array.isArray(tools) || tools.length === 0`. */
    #[Group('ac-04-28')]
    public function testAListingWithoutAToolListIsServedAsIs(): void
    {
        $server = $this->server();

        $this->assertNull(ToolListing::apply($server, null, lastPage: true));
        $this->assertSame([ToolListInjector::NO_TOOLS_WARNING], $this->logger->messages());
        $this->assertNull($server->registry);
    }

    public function testListingAddsGetMoreToolsAndRecordsRegistry(): void
    {
        $server = $this->server();

        $out = ToolListing::apply($server, [$this->tool(self::ECHO_TOOL)], lastPage: true);

        $this->assertSame(['echo', 'get_more_tools'], $this->names($out));
        $this->assertSame(['echo' => ['session_id', 'context'], 'get_more_tools' => ['session_id']], $server->registry);
    }

    /** D66 (B10 fixed): TypeScript appends get_more_tools to every page and keeps only the last page's registries. */
    #[Group('ac-02-8')]
    public function testOnlyTheLastPageGetsGetMoreToolsAndThePagesMerge(): void
    {
        $server = $this->server();

        $page1 = ToolListing::apply($server, [$this->tool('{"name":"a"}')], lastPage: false);
        $page2 = ToolListing::apply($server, [$this->tool('{"name":"b"}')], lastPage: true);

        $this->assertSame(['a'], $this->names($page1));
        $this->assertSame(['b', 'get_more_tools'], $this->names($page2));
        $this->assertSame(['a', 'b', 'get_more_tools'], $this->registered($server));
    }

    public function testDegradedInjectionKeepsThePreviousRegistries(): void
    {
        $server = $this->server();
        ToolListing::apply($server, [$this->tool(self::ECHO_TOOL)], lastPage: true);
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

        $this->assertNull(ToolListing::apply($server, [$hostile], lastPage: true));
        $this->assertSame(
            ['Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - boom'],
            $this->logger->messages(),
        );
        $this->assertSame($before, $server->registry);
        $this->assertNotNull($before);
    }

    /** D66: a registered tool needs no rebuild; before, any registry skipped it. */
    public function testEnsureRegistriesSkipsTheListingWhenTheToolIsRegistered(): void
    {
        $server = $this->server();
        ToolListing::apply($server, [$this->tool('{"name":"a"}')], lastPage: true);
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, 'a', $this->pages(['' => [[], null]], $asked));

        $this->assertSame([], $asked->getArrayCopy());
    }

    #[Group('ac-02-20')]
    public function testEnsureRegistriesRebuildsOnDemand(): void
    {
        $server = $this->server();

        ToolListing::ensureRegistries($server, 'echo', fn(?string $cursor): array => [[$this->tool(self::ECHO_TOOL)], null]);

        $this->assertSame(['echo' => ['session_id', 'context'], 'get_more_tools' => ['session_id']], $server->registry);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    public function testEnsureRegistriesRebuildsFromAnEmptyListing(): void
    {
        $server = $this->server();

        ToolListing::ensureRegistries($server, 'echo', static fn(?string $cursor): array => [[], null]);

        $this->assertSame(['get_more_tools' => ['session_id']], $server->registry);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    public function testEnsureRegistriesIgnoresAMissingListing(): void
    {
        $server = $this->server();

        ToolListing::ensureRegistries($server, 'echo', static fn(?string $cursor): ?array => null);

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

        ToolListing::ensureRegistries($server, 'echo', static fn(?string $cursor): array => [[$hostile], null]);

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
        $list = static function (?string $cursor) use (&$calls): array {
            ++$calls;

            throw new \RuntimeException('list down');
        };

        ToolListing::ensureRegistries($server, 'echo', $list);
        ToolListing::ensureRegistries($server, 'echo', $list);

        $this->assertSame(2, $calls);
        $this->assertNull($server->registry);
        $this->assertSame(
            array_fill(0, 2, 'Warning: registry rebuild-on-demand failed - RuntimeException: list down'),
            $this->logger->messages(),
        );
    }

    #[Group('ac-02-20')]
    public function testTheRebuildReadsEveryPage(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, 'b', $this->pages(['' => [[$this->tool('{"name":"a"}')], 'p2'], 'p2' => [[$this->tool('{"name":"b"}')], null]], $asked));

        $this->assertSame([null, 'p2'], $asked->getArrayCopy());
        $this->assertSame(['a' => self::INJECTED, 'b' => self::INJECTED, 'get_more_tools' => ['session_id']], $server->registry);
        $this->assertTrue($server->everyPageRead);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    #[Group('ac-02-20')]
    public function testACursorAlreadyFollowedEndsTheRebuild(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, 'b', $this->pages(['' => [[$this->tool('{"name":"a"}')], 'c'], 'c' => [[$this->tool('{"name":"b"}')], 'c']], $asked));

        $this->assertSame([null, 'c'], $asked->getArrayCopy());
        $this->assertSame(['a', 'b', 'get_more_tools'], $this->registered($server));
        $this->assertTrue($server->everyPageRead);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    /** @return iterable<string, array{\Throwable|null, string}> */
    public static function unreadablePages(): iterable
    {
        yield 'it throws' => [new \RuntimeException('page down'), 'RuntimeException: page down'];
        yield 'no tool list' => [null, 'page 2 has no tool list'];
    }

    #[DataProvider('unreadablePages')]
    #[Group('ac-02-21')]
    public function testAPageThatCannotBeReadKeepsTheEarlierPagesAndIsTriedAgain(?\Throwable $page, string $reason): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();
        $list = $this->pages(['' => [[$this->tool('{"name":"a"}')], 'p2'], 'p2' => $page], $asked);

        ToolListing::ensureRegistries($server, 'b', $list);

        $this->assertSame(['a' => self::INJECTED], $server->registry); // no get_more_tools entry: the end was not read
        $this->assertFalse($server->everyPageRead);
        $this->assertSame([self::STOPPED . '1 - ' . $reason], $this->logger->messages());

        ToolListing::ensureRegistries($server, 'a', $list);
        $this->assertCount(2, $asked);

        ToolListing::ensureRegistries($server, 'b', $list);
        $this->assertCount(4, $asked);
        $this->assertSame(array_fill(0, 2, self::STOPPED . '1 - ' . $reason), $this->logger->messages());
    }

    #[Group('ac-02-22')]
    public function testTheRebuildStopsAtThePageLimit(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, 'nope', $this->endless($asked));

        $this->assertCount(ToolListing::REBUILD_MAX_PAGES, $asked);
        $this->assertSame(array_map(static fn(int $n): string => 't' . $n, range(0, 99)), $this->registered($server));
        $this->assertSame([self::STOPPED . '100 - the limit is 100 pages'], $this->logger->messages());
        $this->assertFalse($server->everyPageRead);
    }

    #[Group('ac-02-22')]
    public function testTheRebuildStopsWhenItsBudgetIsUsedUp(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();
        $ticks = 0;
        $clock = static function () use (&$ticks): float {
            return 2.5 * $ticks++; // 0.0 at the start, 2.5 after page 1, exactly the budget after page 2
        };

        ToolListing::ensureRegistries($server, 'nope', $this->endless($asked), $clock);

        $this->assertCount(2, $asked);
        $this->assertSame(['t0', 't1'], $this->registered($server)); // no get_more_tools entry: the end was not read
        $this->assertSame([self::STOPPED . '2 - the budget of 5 s is used up'], $this->logger->messages());
        $this->assertFalse($server->everyPageRead);
    }

    /** D66: a client that listed page 1 only, then calls: only a tool that no listing named rebuilds, and only once. */
    #[Group('ac-02-20')]
    public function testOnlyAToolNoListingNamedRebuilds(): void
    {
        $server = $this->server();
        ToolListing::apply($server, [$this->tool('{"name":"a"}')], lastPage: false);
        $asked = new \ArrayObject();
        $list = $this->pages(['' => [[$this->tool('{"name":"a"}')], 'p2'], 'p2' => [[$this->tool('{"name":"b"}')], null]], $asked);

        ToolListing::ensureRegistries($server, 'a', $list);
        $this->assertSame([], $asked->getArrayCopy());

        ToolListing::ensureRegistries($server, 'b', $list);
        $this->assertSame([null, 'p2'], $asked->getArrayCopy());
        $this->assertSame(['a', 'b', 'get_more_tools'], $this->registered($server));

        ToolListing::ensureRegistries($server, 'nope', $list);
        $this->assertSame([null, 'p2'], $asked->getArrayCopy(), 'every page was read');
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function cursors(): iterable
    {
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'the integer 0' => [0, null];
        yield 'a list' => [['c'], null];
        yield 'the string 0' => ['0', '0'];
        yield 'opaque' => ['c', 'c'];
    }

    #[DataProvider('cursors')]
    #[Group('ac-02-8')]
    public function testTheNextCursorIsANonEmptyString(mixed $cursor, ?string $next): void
    {
        $this->assertSame($next, ToolListing::nextCursor($cursor));
    }

    /** Plan F spec conflict 3: the rebuild stops at the first page that names the called tool. */
    #[Group('ac-02-20')]
    public function testARebuildForAPageOneToolReadsOnePage(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, 't01', $this->pages($this->sixteenTools(), $asked));

        $this->assertSame([null], $asked->getArrayCopy());
        $this->assertSame(array_map(static fn(int $i): string => sprintf('t%02d', $i), range(1, 15)), $this->registered($server));
        $this->assertFalse($server->everyPageRead);
        $this->assertSame([self::REBUILT], $this->logger->messages());
    }

    #[Group('ac-02-20')]
    #[Group('ac-02-19')]
    public function testARebuildForAPageTwoToolReadsTwoPages(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, 'own', $this->pages($this->sixteenTools(), $asked));

        $this->assertSame([null, 'p2'], $asked->getArrayCopy());
        $this->assertTrue($server->injection->hasDeclaredSessionParam('own'));
        $this->assertSame(['context'], ArgumentStripper::names('own', $server->registry)); // its session_id stays
        $this->assertTrue($server->everyPageRead);
    }

    /**
     * Review Focus 3: a list handler that gives the same next cursor again. The call answers; the rebuild read two
     * pages and logged one line, and only a tool that no page read named gets the heuristic strip.
     */
    #[Group('ac-02-20')]
    #[Group('ac-04-30')]
    public function testARebuildStopsAtARepeatedCursor(): void
    {
        $server = $this->server();
        $unread = $this->seam('c');
        $unread->listing = [$this->tool('{"name":"a"}')];
        $unread->nextCursor = 'again';
        $unread->pages = ['again' => [[$this->tool('{"name":"b"}')], 'again']];
        $read = $this->seam('b');

        $answer = $this->intercept($server, $unread);
        $this->intercept($server, $read);

        $this->assertSame([null, 'again'], $unread->cursors);
        $this->assertSame([self::REBUILT], $this->logger->messages());
        $this->assertSame('ok', JsonAssert::at($answer, 'content', 1, 'text'));
        $this->assertSame(['stripped', self::HEURISTIC], $unread->calls[0] ?? null);
        $this->assertSame(0, $read->listCalls);
        $this->assertSame(['stripped', self::INJECTED], $read->calls[0] ?? null);
    }

    /**
     * Review Focus 3: a list handler that gives a new cursor every time. The call answers; the rebuild read 100 pages
     * and logged one line, and only a tool that no page read named gets the heuristic strip.
     */
    #[Group('ac-02-22')]
    public function testARebuildStopsAfterOneHundredPages(): void
    {
        $server = $this->server();
        $unread = $this->seam('nope');
        $unread->listing = [$this->tool('{"name":"t0"}')];
        $unread->nextCursor = 'c1';
        foreach (range(1, 2 * ToolListing::REBUILD_MAX_PAGES) as $n) {
            $unread->pages['c' . $n] = [[$this->tool(sprintf('{"name":"t%d"}', $n))], 'c' . ($n + 1)];
        }
        $read = $this->seam('t99');

        $answer = $this->intercept($server, $unread);
        $this->intercept($server, $read);

        $this->assertSame(ToolListing::REBUILD_MAX_PAGES, $unread->listCalls);
        $this->assertSame([self::STOPPED . '100 - the limit is 100 pages'], $this->logger->messages());
        $this->assertSame('ok', JsonAssert::at($answer, 'content', 1, 'text'));
        $this->assertSame(['stripped', self::HEURISTIC], $unread->calls[0] ?? null);
        $this->assertSame(0, $read->listCalls);
        $this->assertSame(['stripped', self::INJECTED], $read->calls[0] ?? null);
    }

    /**
     * A get_more_tools call rebuilds like any other tool (coordinator ruling, Task 2 fix round 0). AgentCat's own is on
     * no page, so the read goes to the last page, which appends it: its entry holds the parameters AgentCat injected.
     */
    #[Group('ac-02-40')]
    public function testAGetMoreToolsCallReadsToTheLastPageForAgentCatsOwn(): void
    {
        $server = $this->server(new Options(enableAgentTracking: true));
        $asked = new \ArrayObject();

        ToolListing::ensureRegistries($server, GetMoreTools::NAME, $this->pages(['' => [[$this->tool(self::ECHO_TOOL)], 'p2'], 'p2' => [[$this->tool('{"name":"b"}')], null]], $asked));

        $this->assertSame([null, 'p2'], $asked->getArrayCopy());
        $this->assertSame(['session_id', 'agent_id'], ArgumentStripper::names(GetMoreTools::NAME, $server->registry));
        $this->assertFalse($server->injection->hasDeclaredSessionParam(GetMoreTools::NAME));
        $this->assertTrue($server->everyPageRead);
    }

    /** A customer's get_more_tools is found on its page, like any tool: its own `session_id` stays its own. */
    #[Group('ac-02-19')]
    #[Group('ac-04-24')]
    public function testACustomerGetMoreToolsIsFoundOnItsPage(): void
    {
        $server = $this->server();
        $asked = new \ArrayObject();
        $customer = $this->tool('{"name":"get_more_tools","inputSchema":{"type":"object","properties":{"session_id":{"type":"string"}},"required":["session_id"]}}');

        ToolListing::ensureRegistries($server, GetMoreTools::NAME, $this->pages(['' => [[$customer], 'p2'], 'p2' => [[$this->tool('{"name":"b"}')], null]], $asked));

        $this->assertSame([null], $asked->getArrayCopy());
        $this->assertTrue($server->injection->hasDeclaredSessionParam(GetMoreTools::NAME));
        $this->assertSame([], ArgumentStripper::names(GetMoreTools::NAME, $server->registry));
    }
}
