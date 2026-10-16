<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Internal\Engine\InjectionConfig;
use AgentCat\Internal\Engine\InjectionState;
use AgentCat\Internal\Engine\ToolListInjector;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Tests\Support\Golden;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ac-04-16')]
#[Group('ac-04-17')]
#[Group('ac-04-18')]
#[Group('ac-04-19')]
#[Group('ac-04-26')]
#[Group('ac-04-27')]
final class ListingFixturesTest extends TestCase
{
    protected function setUp(): void
    {
        Log::setLogger(new MemoryLogger());
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    /**
     * @return iterable<string, array{string, InjectionConfig}>
     */
    public static function configs(): iterable
    {
        yield 'defaults' => ['defaults', new InjectionConfig()];
        yield 'agent_tracking' => ['agent_tracking', new InjectionConfig(enableAgentTracking: true)];
        yield 'hook_mode_agent_tracking' => ['hook_mode_agent_tracking', new InjectionConfig(enableAgentTracking: true, hookMode: true)];
        yield 'hook_mode_no_agent' => ['hook_mode_no_agent', new InjectionConfig(hookMode: true)];
        yield 'tracing_off' => ['tracing_off', new InjectionConfig(enableTracing: false)];
        yield 'context_off_report_missing_off' => ['context_off_report_missing_off', new InjectionConfig(enableReportMissing: false, enableToolCallContext: false)];
        yield 'custom_context_description' => ['custom_context_description', new InjectionConfig(customContextDescription: 'Why?')];
    }

    #[DataProvider('configs')]
    public function testListingMatchesTypeScript(string $key, InjectionConfig $config): void
    {
        $fixture = Golden::json('handles/listing-fixtures.json');
        $this->assertInstanceOf(\stdClass::class, $fixture);
        $expected = $fixture->{$key};
        $this->assertInstanceOf(\stdClass::class, $expected);
        $input = Json::deepCopy($fixture->input_tools);
        $this->assertIsArray($input);
        $this->assertTrue(array_is_list($input));

        $result = ToolListInjector::inject($input, $config, new InjectionState());

        $expectedTools = Json::encodeJs($expected->tools);
        $this->assertNotNull($expectedTools);
        $this->assertSame($expectedTools, Json::encodeJs($result->tools));
        $this->assertJsonModel($result->tools);
        $this->assertSame($this->normalizedRegistry($expected->registry), $this->normalizedRegistry($result->registry));
        $this->assertIsArray($expected->outputRegistry);
        $this->assertEqualsCanonicalizing($expected->outputRegistry, $result->outputRegistry);
    }

    /** Objects are \stdClass and arrays are lists at every level, so later rewrites see the wire shapes. */
    private function assertJsonModel(mixed $value): void
    {
        if (is_array($value)) {
            $this->assertTrue(array_is_list($value), 'JSON objects must be \stdClass, not associative arrays');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            foreach ((array) $value as $child) {
                $this->assertJsonModel($child);
            }
        }
    }

    /**
     * Set semantics: keys and each entry's parameters sorted.
     *
     * @return array<array-key, list<mixed>>
     */
    private function normalizedRegistry(mixed $registry): array
    {
        $entries = $registry instanceof \stdClass ? get_object_vars($registry) : $registry;
        $this->assertIsArray($entries);
        $normalized = [];
        foreach ($entries as $tool => $params) {
            $this->assertIsArray($params);
            $params = array_values($params);
            sort($params);
            $normalized[$tool] = $params;
        }
        ksort($normalized);

        return $normalized;
    }
}
