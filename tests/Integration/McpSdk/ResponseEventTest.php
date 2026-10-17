<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\McpSdk\Internal\Trees;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\McpSdk\CustomerHandler;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\McpSdkVersion;
use AgentCat\Tests\Support\McpSdk\RecordingDispatcher;
use AgentCat\Tests\Support\McpSdk\Tools;
use AgentCat\Tests\Support\McpSdk\TrackingFixture;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Builder;
use PHPUnit\Framework\TestCase;

/**
 * A customer's `ResponseEvent` listeners keep seeing the library's result types with AgentCat: the typed fields are
 * the library's result, and the result encodes exactly as the wire answer AgentCat sends.
 */
final class ResponseEventTest extends TestCase
{
    private TrackingFixture $fixture;

    private RecordingDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new TrackingFixture();
        $this->dispatcher = new RecordingDispatcher();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testListenersSeeTheLibrarysToolListWhileTheWireCarriesTheInjectedOne(): void
    {
        $builder = $this->builder();
        Tools::echo($builder);
        $this->fixture->track($builder);

        $wire = $this->fixture->harness($builder)->listTools();

        $this->assertSame(['echo', GetMoreTools::NAME], $this->names($wire));
        $result = $this->only('tools/list');
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertSame(['echo'], array_map(static fn(Tool $tool): string => $tool->name, array_values($result->tools)));
        $this->assertNull($result->nextCursor);
        $this->assertEquals($wire, Trees::of($result));
    }

    public function testListenersSeeTheToolsOwnResultWhileTheWireCarriesTheMintBack(): void
    {
        $builder = $this->builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);
        $harness->listTools();

        $wire = HttpHarness::result($harness->callTool('echo', ['msg' => 'hi']));

        $this->assertSame('echo: hi', JsonAssert::at($wire, 'content', 1, 'text'), 'the mint-back text comes first on the wire');
        $result = $this->only('tools/call');
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertSame(['echo: hi'], $this->texts($result));
        $this->assertFalse($result->isError);
        $this->assertNull($result->structuredContent);
        $this->assertNull($result->meta);
        $this->assertEquals($wire, Trees::of($result));
    }

    public function testListenersSeeTheGetMoreToolsAnswerAsACallToolResult(): void
    {
        $builder = $this->builder();
        $this->fixture->track($builder);
        $harness = $this->fixture->harness($builder);

        $wire = HttpHarness::result($harness->callTool(GetMoreTools::NAME, ['context' => 'a calendar tool']));

        $result = $this->only('tools/call');
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertSame([JsonAssert::at($wire, 'content', 1, 'text')], $this->texts($result), 'the mint-back text comes first on the wire');
        $this->assertFalse($result->isError);
        $this->assertEquals($wire, Trees::of($result));
    }

    public function testListenersSeeAnotherResultTypeAsTheCallToolResultItsTreeHolds(): void
    {
        $builder = $this->builder();
        $this->fixture->track($builder);
        $builder->addRequestHandler(new CustomerHandler(CallToolRequest::class, static fn(): ResultInterface => new class implements ResultInterface {
            /** @return array<string, mixed> */
            #[\Override]
            public function jsonSerialize(): array
            {
                return ['content' => [['type' => 'text', 'text' => 'custom']], 'isError' => true];
            }
        }));
        $harness = $this->fixture->harness($builder);

        $wire = HttpHarness::result($harness->callTool('anything', []));

        $this->assertSame('custom', JsonAssert::at($wire, 'content', 1, 'text'), 'the mint-back text comes first on the wire');
        $result = $this->only('tools/call');
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertSame(['custom'], $this->texts($result));
        $this->assertTrue($result->isError);
        $this->assertEquals($wire, Trees::of($result));
    }

    public function testModernEraListenersSeeTheLibrarysTypes(): void
    {
        $builder = $this->builder();
        Tools::echo($builder);
        $this->fixture->track($builder);
        $harness = new HttpHarness($builder->build());

        $listing = $harness->listToolsModern();
        $call = HttpHarness::result($harness->callToolModern('echo', ['msg' => 'hi']));

        // mcp/sdk 0.8.1 dispatches no ResponseEvent in the 2026-07-28 era; main does.
        $lists = $this->dispatcher->results('tools/list');
        $calls = $this->dispatcher->results('tools/call');
        $this->assertCount(McpSdkVersion::isMain() ? 1 : 0, $lists);
        $this->assertCount(McpSdkVersion::isMain() ? 1 : 0, $calls);
        foreach ($lists as $result) {
            $this->assertInstanceOf(ListToolsResult::class, $result);
            $this->assertWireCarries($listing, $result);
        }
        foreach ($calls as $result) {
            $this->assertInstanceOf(CallToolResult::class, $result);
            $this->assertSame(['echo: hi'], $this->texts($result));
            $this->assertWireCarries($call, $result);
        }
    }

    private function builder(): Builder
    {
        return TrackingFixture::builder()->setEventDispatcher($this->dispatcher);
    }

    /** Every member of the result is on the wire: the 2026-07-28 codec adds its own (`resultType`, `ttlMs`, …). */
    private function assertWireCarries(\stdClass $wire, mixed $result): void
    {
        $tree = Trees::of($result);
        $this->assertInstanceOf(\stdClass::class, $tree);
        foreach (get_object_vars($tree) as $member => $value) {
            $this->assertEquals($value, $wire->{$member} ?? null, (string) $member);
        }
    }

    /** The one result listeners saw for this method. */
    private function only(string $method): mixed
    {
        $results = $this->dispatcher->results($method);
        $this->assertCount(1, $results);

        return $results[0];
    }

    /** @return list<string> */
    private function names(\stdClass $listing): array
    {
        $tools = $listing->tools ?? null;
        $this->assertIsArray($tools);

        return array_map(static function (mixed $tool): string {
            $name = JsonAssert::at($tool, 'name');
            self::assertIsString($name);

            return $name;
        }, array_values($tools));
    }

    /** @return list<string> the text of every content block, each one a `TextContent` */
    private function texts(CallToolResult $result): array
    {
        return array_map(static function (mixed $content): string {
            self::assertInstanceOf(TextContent::class, $content);
            self::assertIsString($content->text);

            return $content->text;
        }, array_values($result->content));
    }
}
