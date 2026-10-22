<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\McpSdk;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Engine\CallInterceptor;
use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\McpSdk\McpSdkAdapter;
use AgentCat\Options;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\McpSdk\HttpHarness;
use AgentCat\Tests\Support\McpSdk\RecordingTool;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\RecordingTransport;
use Mcp\Schema\Tool;
use Mcp\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The listing a real mcp/sdk server sends with AgentCat equals the TypeScript golden listing, in both protocol eras.
 *
 * Two allowances, both from what mcp/sdk can represent, not from AgentCat:
 * - The fixture's `bare` tool has no `inputSchema`. mcp/sdk cannot represent such a tool (`Tool` requires one), so
 *   it is left out of the input and of the expected listing.
 * - `Tool::jsonSerialize()` writes `description` after `inputSchema`, where TypeScript keeps the customer's order. So
 *   each tool's top-level keys are compared without order. Every nested order (schemas, properties) must match.
 *
 * @phpstan-import-type ToolData from Tool
 */
#[Group('ac-04-16')]
#[Group('ac-04-17')]
#[Group('ac-04-18')]
#[Group('ac-04-19')]
#[Group('ac-04-26')]
#[Group('ac-04-27')]
final class ListingGoldenTest extends TestCase
{
    private const string UNREPRESENTABLE = 'bare';

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

    /** @return iterable<string, array{string, Options}> */
    public static function configs(): iterable
    {
        $hook = static fn(): string => 'customer-session';

        yield 'defaults' => ['defaults', new Options()];
        yield 'agent_tracking' => ['agent_tracking', new Options(enableAgentTracking: true)];
        yield 'hook_mode_agent_tracking' => ['hook_mode_agent_tracking', new Options(enableAgentTracking: true, resolveSessionId: $hook)];
        yield 'hook_mode_no_agent' => ['hook_mode_no_agent', new Options(resolveSessionId: $hook)];
        yield 'tracing_off' => ['tracing_off', new Options(enableTracing: false)];
        yield 'context_off_report_missing_off' => ['context_off_report_missing_off', new Options(enableReportMissing: false, enableToolCallContext: false)];
        yield 'custom_context_description' => ['custom_context_description', new Options(customContextDescription: 'Why?')];
    }

    #[DataProvider('configs')]
    #[Group('ac-01-21')]
    public function testTheInjectedListingMatchesTypeScriptInBothEras(string $key, Options $options): void
    {
        $fixture = Golden::json('handles/listing-fixtures.json');
        $this->assertInstanceOf(\stdClass::class, $fixture);
        $golden = $fixture->{$key};
        $this->assertInstanceOf(\stdClass::class, $golden);
        $expected = $this->comparable($golden->tools);
        $harness = $this->harness($options);

        $this->assertSame($expected, $this->comparable($harness->listTools()->tools), 'handshake era');
        $this->assertSame($expected, $this->comparable($harness->listToolsModern()->tools), 'modern era');
    }

    private function harness(Options $options): HttpHarness
    {
        $builder = Server::builder()->setServerInfo('golden', '1.0.0');
        foreach ($this->inputTools() as $tool) {
            $builder->add(Tool::fromArray($tool), new RecordingTool());
        }
        $publisher = new Publisher(new EventQueue(transport: new RecordingTransport()));
        McpSdkAdapter::install($builder, new TrackedServer('proj_aaaaaaaaaaaaaaaaaaaaaaaaaaa', $options, McpSdkAdapter::serverInfo($builder), $publisher), $publisher, new CallInterceptor($publisher));
        $harness = new HttpHarness($builder->build());
        $harness->initialize();

        return $harness;
    }

    /** @return list<ToolData> the fixture's input tools that mcp/sdk can represent, as mcp/sdk tool data */
    private function inputTools(): array
    {
        $fixture = json_decode(Golden::text('handles/listing-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($fixture);
        $this->assertIsList($fixture['input_tools'] ?? null);
        /** @var list<ToolData> $tools decoded fixture data; Tool::fromArray() validates each entry */
        $tools = array_values(array_filter($fixture['input_tools'], static fn(mixed $tool): bool => is_array($tool) && ($tool['name'] ?? null) !== self::UNREPRESENTABLE));

        return $tools;
    }

    /** The tools without the unrepresentable one, top-level keys sorted, encoded: nested order still counts. */
    private function comparable(mixed $tools): string
    {
        $this->assertIsList($tools);
        $sorted = [];
        foreach ($tools as $tool) {
            $this->assertInstanceOf(\stdClass::class, $tool);
            if (($tool->name ?? null) === self::UNREPRESENTABLE) {
                continue;
            }
            $members = get_object_vars($tool);
            ksort($members, SORT_STRING);
            $sorted[] = (object) $members;
        }
        $encoded = Json::encodeJs($sorted);
        $this->assertNotNull($encoded);

        return $encoded;
    }
}
