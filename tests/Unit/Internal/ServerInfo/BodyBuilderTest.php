<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\ServerInfo;

use AgentCat\Internal\Engine\InjectionConfig;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\ServerInfo\BodyBuilder;
use AgentCat\Internal\ServerInfo\ServerInfoBody;
use AgentCat\Internal\ServerInfo\ServerInfoSource;
use AgentCat\Internal\ServerInfo\Skipped;
use AgentCat\Tests\Support\FakeServerInfo;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;

final class BodyBuilderTest extends TestCase
{
    private const string PROJECT = 'proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private float $now = 100.0;

    #[\Override]
    protected function setUp(): void
    {
        Log::setLogger(new MemoryLogger());
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testV1TheBodyHoldsTheSixKeysAndEveryListComplete(): void
    {
        $body = $this->build(new FakeServerInfo());

        $json = json_decode($body->encode(), false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $json);
        $this->assertSame(['project_id', 'server_name', 'server_version', 'server_title', 'instructions', 'features'], JsonAssert::keys($json));
        $this->assertSame([self::PROJECT, 'demo-server', '1.2.3', 'Demo Title', 'Use the demo tools.'], [JsonAssert::at($json, 'project_id'), JsonAssert::at($json, 'server_name'), JsonAssert::at($json, 'server_version'), JsonAssert::at($json, 'server_title'), JsonAssert::at($json, 'instructions')]);
        $this->assertSame(['tools', 'resources', 'resourceTemplates', 'prompts'], JsonAssert::keys(JsonAssert::at($json, 'features')));
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], JsonAssert::column(JsonAssert::at($json, 'features', 'tools'), 'name'));
        $beta = JsonAssert::at($json, 'features', 'tools', 1);
        $this->assertSame(str_repeat('b', 2000), JsonAssert::at($beta, 'description'));
        $this->assertSame('Beta Tool', JsonAssert::at($beta, 'title'));
        $this->assertTrue(JsonAssert::at($beta, 'annotations', 'readOnlyHint'));
        $this->assertSame(['n', 'session_id', 'context'], JsonAssert::keys(JsonAssert::at($beta, 'inputSchema', 'properties')));
        $this->assertSame(['sum', 'mcp_session'], JsonAssert::keys(JsonAssert::at($beta, 'outputSchema', 'properties')));
        $this->assertCount(2, JsonAssert::list(JsonAssert::at($json, 'features', 'resources')));
        $this->assertCount(1, JsonAssert::list(JsonAssert::at($json, 'features', 'resourceTemplates')));
        $this->assertCount(2, JsonAssert::list(JsonAssert::at($json, 'features', 'prompts')));
        $this->assertSame('who', JsonAssert::at($json, 'features', 'prompts', 0, 'arguments', 0, 'name'));
        $this->assertSame(4, $body->toolCount());
        $this->assertSame([], $body->failedLists);
    }

    public function testV2AToolListOnThreePagesIsReadWholeAndGetsGetMoreToolsOnce(): void
    {
        $source = new FakeServerInfo([ServerInfoSource::TOOLS => [
            '' => [[FakeServerInfo::tool('t1'), FakeServerInfo::tool('t2')], 'p2'],
            'p2' => [[FakeServerInfo::tool('t3'), FakeServerInfo::tool('t4')], 'p3'],
            'p3' => [[FakeServerInfo::tool('t5')], null],
        ]]);

        $body = $this->build($source);

        $this->assertSame(['t1', 't2', 't3', 't4', 't5', 'get_more_tools'], $this->toolNames($body));
        $this->assertSame(['tools@', 'tools@p2', 'tools@p3'], array_slice($source->asked, 0, 3));
    }

    public function testV3AServerWithToolsOnlyHasNoOtherKey(): void
    {
        $body = $this->build(new FakeServerInfo($this->demoTools()));

        $this->assertSame([ServerInfoSource::TOOLS], array_keys($body->features));
        $this->assertSame([], $body->failedLists);
    }

    public function testAnEmptyListFromAHandlerIsAnEmptyArray(): void
    {
        $body = $this->build(new FakeServerInfo([...$this->demoTools(), ServerInfoSource::RESOURCES => ['' => [[], null]]]));

        $this->assertSame([], $this->items($body, ServerInfoSource::RESOURCES));
        $this->assertStringContainsString('"resources":[]', $body->encode());
    }

    public function testV4AFailingPromptListLeavesItsKeyOutAndTheToolsComplete(): void
    {
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::PROMPTS] = new \RuntimeException('no prompts today');

        $body = $this->build(new FakeServerInfo($lists));

        $this->assertSame([ServerInfoSource::TOOLS, ServerInfoSource::RESOURCES, ServerInfoSource::RESOURCE_TEMPLATES], array_keys($body->features));
        $this->assertSame(['alpha', 'beta', 'gamma', 'get_more_tools'], $this->toolNames($body));
        $this->assertSame([ServerInfoSource::PROMPTS => 'RuntimeException: no prompts today'], $body->failedLists);
    }

    public function testV5AFailingToolListStopsTheBuild(): void
    {
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::TOOLS] = new \RuntimeException('no tools today');

        $skipped = Thrown::by(Skipped::class, fn(): ServerInfoBody => $this->build(new FakeServerInfo($lists)));

        $this->assertSame('the tool list failed - RuntimeException: no tools today', $skipped->getMessage());
        $this->assertSame(0, $skipped->getCode());
        $this->assertSame($lists[ServerInfoSource::TOOLS], $skipped->getPrevious());
    }

    public function testAServerWithoutAToolListHandlerStopsTheBuild(): void
    {
        $this->expectException(Skipped::class);
        $this->expectExceptionMessage('the server has no tool list');
        $this->build(new FakeServerInfo([]));
    }

    public function testV6AListWhoseCursorRepeatsFailsAtOnce(): void
    {
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::RESOURCES] = static fn(?string $cursor): array => [[(object) ['uri' => 'file:///x', 'name' => 'x']], 'again'];
        $source = new FakeServerInfo($lists);
        $started = microtime(true);

        $body = $this->build($source);

        $this->assertLessThan(5.0, microtime(true) - $started);
        $this->assertArrayNotHasKey(ServerInfoSource::RESOURCES, $body->features);
        $this->assertSame('the cursor "again" came back after page 2', ($body->failedLists[ServerInfoSource::RESOURCES] ?? null));
        $this->assertSame(['resources@', 'resources@again'], array_values(array_filter($source->asked, static fn(string $asked): bool => str_starts_with($asked, 'resources@'))));
    }

    public function testV6AToolListWhoseCursorRepeatsStopsTheBuild(): void
    {
        $this->expectException(Skipped::class);
        $this->expectExceptionMessage('the tool list failed - the cursor "again" came back after page 2');
        $this->build(new FakeServerInfo([ServerInfoSource::TOOLS => static fn(?string $cursor): array => [[FakeServerInfo::tool('t')], 'again']]));
    }

    public function testAListOfExactlyOneHundredPagesIsReadAndOneMorePageFailsIt(): void
    {
        $paged = static fn(int $pages): \Closure => static function (?string $cursor) use ($pages): array {
            $page = $cursor === null ? 1 : (int) $cursor;

            return [[(object) ['name' => 'p' . $page]], $page < $pages ? (string) ($page + 1) : null];
        };
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::PROMPTS] = $paged(100);
        $lists[ServerInfoSource::RESOURCES] = $paged(101);
        $source = new FakeServerInfo($lists);

        $body = $this->build($source);

        $this->assertCount(100, $this->items($body, ServerInfoSource::PROMPTS));
        $this->assertSame('it has more than 100 pages', ($body->failedLists[ServerInfoSource::RESOURCES] ?? null));
        $this->assertCount(100, array_filter($source->asked, static fn(string $asked): bool => str_starts_with($asked, 'resources@')));
    }

    public function testAListThatTakesMoreThanFiveSecondsFailsBetweenTwoPages(): void
    {
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::RESOURCES] = function (?string $cursor): array {
            $this->now += 2.6; // two pages take more than 5 s

            return [[(object) ['name' => 'r']], $cursor === null ? 'p2' : ($cursor === 'p2' ? 'p3' : null)];
        };
        $lists[ServerInfoSource::PROMPTS] = function (?string $cursor): array {
            $this->now += 2.5; // two pages take exactly 5 s, which is not more

            return [[(object) ['name' => 'p']], $cursor === null ? 'p2' : ($cursor === 'p2' ? 'p3' : null)];
        };
        $source = new FakeServerInfo($lists);

        $body = $this->build($source);

        $this->assertSame('it took more than 5 s', ($body->failedLists[ServerInfoSource::RESOURCES] ?? null));
        $this->assertNotContains('resources@p3', $source->asked);
        $this->assertCount(3, $this->items($body, ServerInfoSource::PROMPTS));
    }

    public function testASlowOnePageListIsComplete(): void
    {
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::RESOURCES] = function (): array {
            $this->now += 9.0;

            return [[(object) ['name' => 'r']], null];
        };

        $this->assertCount(1, $this->items($this->build(new FakeServerInfo($lists)), ServerInfoSource::RESOURCES));
    }

    public function testThePageCheckStopsTheBuildBetweenPages(): void
    {
        $checks = 0;

        $source = new FakeServerInfo();

        $skipped = Thrown::by(Skipped::class, fn(): ServerInfoBody => BodyBuilder::build(self::PROJECT, 'demo-server', '1.2.3', $source, new InjectionConfig(), fn(): float => $this->now, static function () use (&$checks): void {
            if (++$checks === 3) {
                throw new Skipped('out of time');
            }
        }));

        $this->assertSame('out of time', $skipped->getMessage());
        $this->assertSame(['tools@', 'resources@'], $source->asked, 'the check ran before each page, and the third page was not asked for');
    }

    public function testToolsThatAreAdvertisedAlreadyAreNotInjectedAgain(): void
    {
        $body = $this->build(new FakeServerInfo(advertised: true));

        $this->assertSame(['alpha', 'beta', 'gamma'], $this->toolNames($body));
        $this->assertEquals(FakeServerInfo::beta(), JsonAssert::at($this->items($body, ServerInfoSource::TOOLS), 1));
    }

    public function testAnEmptyToolListStaysEmptyAsAClientGetsIt(): void
    {
        $body = $this->build(new FakeServerInfo([ServerInfoSource::TOOLS => ['' => [[], null]]]));

        $this->assertSame([], $this->items($body, ServerInfoSource::TOOLS));
        $this->assertSame(0, $body->toolCount());
    }

    public function testTheInjectionFollowsTheTrackingOptions(): void
    {
        $body = BodyBuilder::build(self::PROJECT, 'demo-server', null, new FakeServerInfo(), new InjectionConfig(enableReportMissing: false, enableToolCallContext: false), fn(): float => $this->now, static function (): void {});

        $this->assertSame(['alpha', 'beta', 'gamma'], $this->toolNames($body));
        $json = json_decode($body->encode(), false, flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(\stdClass::class, $json);
        $this->assertObjectNotHasProperty('server_version', $json);
        $this->assertSame(['q', 'session_id'], JsonAssert::keys(JsonAssert::at($json, 'features', 'tools', 0, 'inputSchema', 'properties')));
    }

    public function testWithoutSchemasEveryToolKeepsItsNameTitleAndDescription(): void
    {
        $body = $this->build(new FakeServerInfo());

        $small = $body->withoutSchemas();

        $this->assertNotSame($body, $small);
        foreach ($this->items($small, ServerInfoSource::TOOLS) as $index => $tool) {
            $full = JsonAssert::at($this->items($body, ServerInfoSource::TOOLS), $index);
            $this->assertObjectNotHasProperty('inputSchema', JsonAssert::object($tool));
            $this->assertObjectNotHasProperty('outputSchema', JsonAssert::object($tool));
            $this->assertSame([JsonAssert::at($full, 'name'), JsonAssert::optional($full, 'title'), JsonAssert::at($full, 'description')], [JsonAssert::at($tool, 'name'), JsonAssert::optional($tool, 'title'), JsonAssert::at($tool, 'description')]);
        }
        $this->assertObjectHasProperty('inputSchema', JsonAssert::object(JsonAssert::at($this->items($body, ServerInfoSource::TOOLS), 1)), 'the full body is unchanged');
        $this->assertTrue(JsonAssert::at($this->items($small, ServerInfoSource::TOOLS), 1, 'annotations', 'readOnlyHint'));
        $this->assertSame($this->items($body, ServerInfoSource::PROMPTS), $this->items($small, ServerInfoSource::PROMPTS));
        $this->assertSame(4, $small->toolCount());
    }

    public function testAListWhoseLaterPageHasNoListFails(): void
    {
        $lists = FakeServerInfo::demo();
        $lists[ServerInfoSource::RESOURCES] = static fn(?string $cursor): ?array => $cursor === null ? [[(object) ['name' => 'r']], 'p2'] : null;

        $body = $this->build(new FakeServerInfo($lists));

        $this->assertSame('page 2 has no list', ($body->failedLists[ServerInfoSource::RESOURCES] ?? null));
    }

    /**
     * The demo server's tool list alone.
     *
     * @return array<string, array<string, array{list<mixed>, ?string}>>
     */
    private function demoTools(): array
    {
        return array_intersect_key(FakeServerInfo::demo(), [ServerInfoSource::TOOLS => true]);
    }

    /** @return list<mixed> the items of a list that the body has */
    private function items(ServerInfoBody $body, string $list): array
    {
        $this->assertArrayHasKey($list, $body->features);

        return $body->features[$list];
    }

    private function build(FakeServerInfo $source): ServerInfoBody
    {
        return BodyBuilder::build(self::PROJECT, 'demo-server', '1.2.3', $source, new InjectionConfig(), fn(): float => $this->now, static function (): void {});
    }

    /** @return list<mixed> */
    private function toolNames(ServerInfoBody $body): array
    {
        return JsonAssert::column($this->items($body, ServerInfoSource::TOOLS), 'name');
    }
}
