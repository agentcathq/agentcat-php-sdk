<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Engine;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Engine\InjectedList;
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

final class ToolListInjectorTest extends TestCase
{
    private const string CONFLICT_ERROR = 'ERROR: Tool "own" already declares a \'session_id\' parameter. AgentCat will not inject its own, and calls to this tool are published without a session, so they cannot be correlated. Your parameter is untouched and still reaches your handler. If you already manage sessions, pass a resolveSessionId hook to track() — AgentCat will derive its session from your identifier and stop injecting session_id entirely.';

    private const string SHADOW_WARN = 'WARN: This server registers its own "get_more_tools" tool, which is shadowed by AgentCat\'s report-missing tool: calls to it are answered by AgentCat and never reach your handler. Rename your tool, or set enableReportMissing: false to keep yours reachable.';

    private const string INJECTED_ONLY = '{"type":"object","properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]}';

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        Log::reset();
    }

    #[Group('ac-04-28')]
    public function testNoToolsWarningIsVerbatim(): void
    {
        // sha256 of 'Warning: No tools found in the original list. This is likely due to the tools not being registered before AgentCat.track().'
        $this->assertSame('5970b84f5767f0d68536e20b1f96b84abd476ea53e9085b024233269b3cc1ced', hash('sha256', ToolListInjector::NO_TOOLS_WARNING));
    }

    public function testConfigDefaults(): void
    {
        $config = new InjectionConfig();

        $this->assertTrue($config->enableReportMissing);
        $this->assertTrue($config->enableTracing);
        $this->assertTrue($config->enableToolCallContext);
        $this->assertNull($config->customContextDescription);
        $this->assertFalse($config->enableAgentTracking);
        $this->assertFalse($config->hookMode);
    }

    /**
     * @return iterable<string, array{bool, bool, bool, bool, bool}>
     */
    public static function handleModes(): iterable
    {
        // enableTracing, hookMode, enableAgentTracking => injectSessionId, injectAgentId
        yield 'prompted' => [true, false, false, true, false];
        yield 'prompted + agent' => [true, false, true, true, true];
        yield 'hook' => [true, true, false, false, false];
        yield 'hook + agent' => [true, true, true, false, true];
        yield 'tracing off + agent' => [false, false, true, false, false];
        yield 'tracing off + hook + agent' => [false, true, true, false, false];
    }

    #[DataProvider('handleModes')]
    public function testConfigDerivesHandleInjection(bool $tracing, bool $hook, bool $agent, bool $expectSession, bool $expectAgent): void
    {
        $config = new InjectionConfig(enableTracing: $tracing, enableAgentTracking: $agent, hookMode: $hook);

        $this->assertSame($expectSession, $config->injectSessionId());
        $this->assertSame($expectAgent, $config->injectAgentId());
    }

    public function testStateTracksDeclaredParamsAndOneTimeLogs(): void
    {
        $state = new InjectionState();

        $this->assertSame([], $state->declaredSessionParams());
        $this->assertFalse($state->hasDeclaredSessionParam('a'));
        $state->markDeclaredSessionParam('a');
        $state->markDeclaredSessionParam('a');
        $this->assertTrue($state->hasDeclaredSessionParam('a'));
        $this->assertFalse($state->hasDeclaredSessionParam('b'));
        $this->assertSame(['a' => true], $state->declaredSessionParams());

        $this->assertTrue($state->reportConflictOnce('a'));
        $this->assertFalse($state->reportConflictOnce('a'));
        $this->assertTrue($state->reportConflictOnce('b'));

        $this->assertTrue($state->warnShadowOnce());
        $this->assertFalse($state->warnShadowOnce());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function composedRoots(): iterable
    {
        yield 'oneOf: []' => ['"oneOf":[]'];
        yield 'allOf: []' => ['"allOf":[]'];
        yield 'anyOf: [{}]' => ['"anyOf":[{}]'];
    }

    #[DataProvider('composedRoots')]
    #[Group('ac-04-20')]
    public function testComposedRootSchemaIsSkippedByBothPasses(string $composed): void
    {
        $json = '{"name":"u","inputSchema":{"type":"object",' . $composed . ',"properties":{"a":{"type":"string"}},"required":["a"],"additionalProperties":false},"outputSchema":{"type":"object","properties":{"n":{"type":"number"}}}}';

        $result = $this->inject([$this->decode($json)], new InjectionConfig(enableReportMissing: false, enableAgentTracking: true));

        $this->assertSame($json, $this->toolJson($result, 0));
        $this->assertSame(['u' => []], $result->registry);
        $this->assertSame([], $result->outputRegistry);
        $this->assertSame([
            'WARN: Tool "u" has complex schema (oneOf/allOf/anyOf). Skipping handle injection.',
            'WARN: Tool "u" has complex schema (oneOf/allOf/anyOf). Skipping context injection.',
        ], $this->logger->messages());
    }

    #[Group('ac-04-20')]
    public function testComposedSchemaWithRootSessionIdIsRecordedWithoutConflictReport(): void
    {
        $state = new InjectionState();

        $this->inject([$this->decode('{"name":"u","inputSchema":{"anyOf":[],"properties":{"session_id":{"type":"string"}}}}')], state: $state);

        $this->assertTrue($state->hasDeclaredSessionParam('u'));
        $this->assertSame([
            'WARN: Tool "u" has complex schema (oneOf/allOf/anyOf). Skipping handle injection.',
            'WARN: Tool "u" has complex schema (oneOf/allOf/anyOf). Skipping context injection.',
        ], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{string, InjectionConfig}>
     */
    public static function composedWithoutOwnership(): iterable
    {
        yield 'hook mode' => ['{"anyOf":[],"properties":{"session_id":{"type":"string"}}}', new InjectionConfig(enableReportMissing: false, enableAgentTracking: true, hookMode: true)];
        yield 'falsy session_id' => ['{"anyOf":[],"properties":{"session_id":false}}', new InjectionConfig(enableReportMissing: false)];
        yield 'properties not an object' => ['{"anyOf":[],"properties":"session_id"}', new InjectionConfig(enableReportMissing: false)];
    }

    #[DataProvider('composedWithoutOwnership')]
    #[Group('ac-04-20')]
    public function testComposedSchemaWithoutOwnSessionIdRecordsNothing(string $schema, InjectionConfig $config): void
    {
        $state = new InjectionState();

        $result = $this->inject([$this->decode('{"name":"u","inputSchema":' . $schema . '}')], $config, $state);

        $this->assertFalse($state->hasDeclaredSessionParam('u'));
        $this->assertSame(['u' => []], $result->registry);
    }

    #[Group('ac-04-21')]
    public function testCustomerSessionIdIsUntouchedAndReportedOncePerState(): void
    {
        $json = '{"name":"own","inputSchema":{"type":"object","properties":{"session_id":{"type":"string","description":"mine"}}}}';
        $config = new InjectionConfig(enableReportMissing: false, enableToolCallContext: false);
        $state = new InjectionState();

        $first = $this->inject([$this->decode($json)], $config, $state);
        $second = $this->inject([$this->decode($json)], $config, $state);

        $this->assertSame($json, $this->toolJson($first, 0));
        $this->assertSame($json, $this->toolJson($second, 0));
        $this->assertSame(['own' => []], $first->registry);
        $this->assertTrue($state->hasDeclaredSessionParam('own'));
        $this->assertSame([self::CONFLICT_ERROR], $this->logger->messages());

        $this->inject([$this->decode($json)], $config, new InjectionState());
        $this->assertSame([self::CONFLICT_ERROR, self::CONFLICT_ERROR], $this->logger->messages());
    }

    #[Group('ac-04-21')]
    public function testCustomerSessionIdStillLosesAdditionalPropertiesFalse(): void
    {
        $result = $this->inject(
            [$this->decode('{"name":"own","inputSchema":{"properties":{"session_id":{"type":"string"}},"additionalProperties":false}}')],
            new InjectionConfig(enableReportMissing: false, enableToolCallContext: false),
        );

        $this->assertSame('{"name":"own","inputSchema":{"properties":{"session_id":{"type":"string"}}}}', $this->toolJson($result, 0));
    }

    #[Group('ac-04-21')]
    public function testHookModeNeverRecordsOrReportsCustomerSessionId(): void
    {
        $state = new InjectionState();

        $this->inject(
            [$this->decode('{"name":"own","inputSchema":{"properties":{"session_id":{"type":"string"}}}}')],
            new InjectionConfig(enableReportMissing: false, enableToolCallContext: false, enableAgentTracking: true, hookMode: true),
            $state,
        );

        $this->assertFalse($state->hasDeclaredSessionParam('own'));
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-04-22')]
    public function testCustomerAgentIdAndContextAreSkippedWithWarnings(): void
    {
        $json = '{"name":"mine","inputSchema":{"type":"object","properties":{"agent_id":{"type":"string"},"context":{"type":"string"}},"required":["agent_id"]}}';

        $result = $this->inject([$this->decode($json)], new InjectionConfig(enableReportMissing: false, enableAgentTracking: true, hookMode: true));

        $this->assertSame($json, $this->toolJson($result, 0));
        $this->assertSame(['mine' => []], $result->registry);
        $this->assertSame([
            'WARN: Tool "mine" already has \'agent_id\' parameter. Skipping agent_id injection.',
            'WARN: Tool "mine" already has \'context\' parameter. Skipping context injection.',
        ], $this->logger->messages());
    }

    #[Group('ac-04-22')]
    public function testCustomerAgentIdDoesNotBlockSessionId(): void
    {
        $result = $this->inject(
            [$this->decode('{"name":"mine","inputSchema":{"properties":{"agent_id":{"type":"string"}},"required":["agent_id"]}}')],
            new InjectionConfig(enableReportMissing: false, enableToolCallContext: false, enableAgentTracking: true),
        );

        $this->assertSame(
            $this->expand('{"name":"mine","inputSchema":{"properties":{"agent_id":{"type":"string"},"session_id":"@SID"},"required":["agent_id","session_id"]}}'),
            $this->toolJson($result, 0),
        );
        $this->assertSame(['mine' => ['session_id']], $result->registry);
    }

    #[Group('ac-04-22')]
    public function testCustomerContextIsCheckedBeforeComposedSchema(): void
    {
        $this->inject(
            [$this->decode('{"name":"c","inputSchema":{"oneOf":[],"properties":{"context":{"type":"string"}}}}')],
            new InjectionConfig(enableReportMissing: false, enableTracing: false),
        );

        $this->assertSame(['WARN: Tool "c" already has \'context\' parameter. Skipping context injection.'], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function falsyValues(): iterable
    {
        yield 'false' => ['false'];
        yield 'null' => ['null'];
        yield '0' => ['0'];
        yield 'empty string' => ['""'];
    }

    #[DataProvider('falsyValues')]
    #[Group('ac-04-23')]
    public function testFalsyCustomerPropertiesAreOverwrittenInPlace(string $falsy): void
    {
        $json = '{"name":"f","inputSchema":{"properties":{"session_id":' . $falsy . ',"agent_id":' . $falsy . ',"context":' . $falsy . ',"text":{"type":"string"}}}}';

        $result = $this->inject([$this->decode($json)], new InjectionConfig(enableReportMissing: false, enableAgentTracking: true));

        $this->assertSame(
            $this->expand('{"name":"f","inputSchema":{"properties":{"session_id":"@SID","agent_id":"@AID","context":"@CTX","text":{"type":"string"}},"required":["session_id","agent_id","context"]}}'),
            $this->toolJson($result, 0),
        );
        $this->assertSame(['f' => ['session_id', 'agent_id', 'context']], $result->registry);
        $this->assertSame([], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function truthyEmptyValues(): iterable
    {
        yield 'empty list' => ['[]'];
        yield 'empty object' => ['{}'];
    }

    #[DataProvider('truthyEmptyValues')]
    #[Group('ac-04-21')]
    #[Group('ac-04-22')]
    public function testEmptyListOrObjectCountsAsPresentLikeJs(string $value): void
    {
        $json = '{"name":"own","inputSchema":{"properties":{"session_id":' . $value . ',"agent_id":' . $value . ',"context":' . $value . '}}}';
        $state = new InjectionState();

        $result = $this->inject([$this->decode($json)], new InjectionConfig(enableReportMissing: false, enableAgentTracking: true), $state);

        $this->assertSame($json, $this->toolJson($result, 0));
        $this->assertSame(['own' => []], $result->registry);
        $this->assertTrue($state->hasDeclaredSessionParam('own'));
        $this->assertSame([
            self::CONFLICT_ERROR,
            'WARN: Tool "own" already has \'agent_id\' parameter. Skipping agent_id injection.',
            'WARN: Tool "own" already has \'context\' parameter. Skipping context injection.',
        ], $this->logger->messages());
    }

    #[Group('ac-04-24')]
    public function testCustomerGetMoreToolsIsKeptAndShadowWarnedOncePerState(): void
    {
        $customer = '{"name":"get_more_tools","description":"mine","inputSchema":{"type":"object","properties":{"q":{"type":"string"}},"additionalProperties":false}}';
        $state = new InjectionState();

        $first = $this->inject([$this->decode('{"name":"a","inputSchema":{"oneOf":[]}}'), $this->decode($customer)], new InjectionConfig(), $state);
        $this->inject([$this->decode($customer)], new InjectionConfig(), $state);

        $this->assertCount(2, $first->tools);
        $this->assertSame(
            $this->expand('{"name":"get_more_tools","description":"mine","inputSchema":{"type":"object","properties":{"q":{"type":"string"},"session_id":"@SID"},"required":["session_id"]}}'),
            $this->toolJson($first, 1),
        );
        $this->assertSame(['get_more_tools' => ['session_id'], 'a' => []], $first->registry);
        $this->assertSame(1, $this->logCount(self::SHADOW_WARN));
        $this->assertSame(self::SHADOW_WARN, $this->logger->messages()[0] ?? null);

        $this->inject([$this->decode($customer)], new InjectionConfig(), new InjectionState());
        $this->assertSame(2, $this->logCount(self::SHADOW_WARN));
    }

    #[Group('ac-04-48')]
    public function testContextPassSkipsCustomerGetMoreToolsEvenWithReportMissingOff(): void
    {
        $customer = '{"name":"get_more_tools","inputSchema":{"type":"object","properties":{}}}';

        $result = $this->inject([$this->decode($customer)], new InjectionConfig(enableReportMissing: false, enableTracing: false));

        $this->assertCount(1, $result->tools);
        $this->assertSame($customer, $this->toolJson($result, 0));
        $this->assertSame(['get_more_tools' => []], $result->registry);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-04-45')]
    public function testGetMoreToolsIsAppendedLastOnlyWhenEnabled(): void
    {
        $with = $this->inject([$this->decode('{"name":"a"}')], new InjectionConfig());
        $without = $this->inject([$this->decode('{"name":"a"}')], new InjectionConfig(enableReportMissing: false));

        $this->assertCount(2, $with->tools);
        $this->assertSame(
            $this->expand('{"name":"get_more_tools","description":"Check for additional tools whenever your task might benefit from specialized capabilities - even if existing tools could work as a fallback.","inputSchema":{"type":"object","properties":{"context":{"type":"string","description":"A description of your goal and what kind of tool would help accomplish it."},"session_id":"@SID"},"required":["context","session_id"]},"annotations":{"title":"Get More Tools","readOnlyHint":true,"destructiveHint":false,"idempotentHint":true,"openWorldHint":false}}'),
            $this->toolJson($with, 1),
        );
        $this->assertCount(1, $without->tools);
        $this->assertSame([], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectSchemas(): iterable
    {
        yield 'true' => ['true'];
        yield 'non-empty list' => ['[1]'];
        yield 'list' => ['[{"type":"string"}]'];
        yield 'string' => ['"x"'];
        yield 'number' => ['5'];
    }

    #[DataProvider('nonObjectSchemas')]
    #[Group('ac-04-25')]
    public function testNonObjectInputSchemaIsListedUnmodified(string $schema): void
    {
        $bad = '{"name":"bad","inputSchema":' . $schema . '}';

        $result = $this->inject([$this->decode($bad), $this->decode('{"name":"ok"}')]);

        $this->assertSame($bad, $this->toolJson($result, 0));
        $this->assertSame($this->expand('{"name":"ok","inputSchema":' . self::INJECTED_ONLY . '}'), $this->toolJson($result, 1));
        $this->assertSame(['ok' => ['session_id', 'context'], 'bad' => []], $result->registry);
        $this->assertSame([
            'WARN: Handle injection failed for tool "bad"; listing it unmodified - inputSchema is not a JSON object',
            'WARN: Context injection failed for tool "bad"; listing it unmodified - inputSchema is not a JSON object',
        ], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonObjectProperties(): iterable
    {
        yield 'true' => ['true'];
        yield 'non-empty list' => ['[1]'];
        yield 'string' => ['"abc"'];
    }

    #[DataProvider('nonObjectProperties')]
    #[Group('ac-04-25')]
    public function testNonObjectInputPropertiesAreListedUnmodified(string $properties): void
    {
        $bad = '{"name":"bad","inputSchema":{"type":"object","properties":' . $properties . ',"additionalProperties":false}}';

        $result = $this->inject([$this->decode($bad)]);

        $this->assertSame($bad, $this->toolJson($result, 0));
        $this->assertSame(['bad' => []], $result->registry);
        $this->assertSame([
            'WARN: Handle injection failed for tool "bad"; listing it unmodified - inputSchema.properties is not a JSON object',
            'WARN: Context injection failed for tool "bad"; listing it unmodified - inputSchema.properties is not a JSON object',
        ], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{string, string, list<string>, InjectionConfig}>
     */
    public static function emptyListSchemas(): iterable
    {
        $prompted = new InjectionConfig(enableReportMissing: false);
        $contextOnly = new InjectionConfig(enableReportMissing: false, enableTracing: false);

        yield 'inputSchema: [] gets no type' => [
            '{"name":"t","inputSchema":[]}',
            '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]}}',
            [],
            $prompted,
        ];
        yield 'inputSchema: [], context only' => [
            '{"name":"t","inputSchema":[]}',
            '{"name":"t","inputSchema":{"properties":{"context":"@CTX"},"required":["context"]}}',
            [],
            $contextOnly,
        ];
        yield 'inputSchema.properties: [] keeps its key position' => [
            '{"name":"t","inputSchema":{"properties":[],"type":"object","additionalProperties":false}}',
            '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"type":"object","required":["session_id","context"]}}',
            [],
            $prompted,
        ];
        yield 'inputSchema.properties: [], context only' => [
            '{"name":"t","inputSchema":{"type":"object","properties":[]}}',
            '{"name":"t","inputSchema":{"type":"object","properties":{"context":"@CTX"},"required":["context"]}}',
            [],
            $contextOnly,
        ];
        yield 'outputSchema: []' => [
            '{"name":"t","inputSchema":{},"outputSchema":[],"title":"T"}',
            '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]},"outputSchema":{"properties":{"mcp_session":"@MCP"}},"title":"T"}',
            ['t'],
            $prompted,
        ];
        yield 'outputSchema.properties: []' => [
            '{"name":"t","inputSchema":{},"outputSchema":{"type":"object","properties":[],"additionalProperties":false}}',
            '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]},"outputSchema":{"type":"object","properties":{"mcp_session":"@MCP"},"additionalProperties":false}}',
            ['t'],
            $prompted,
        ];
    }

    /**
     * PHP divergence from TS: PHP encodes an empty map as `[]`, so an empty list counts as an empty object.
     *
     * @param list<string> $outputRegistry
     */
    #[DataProvider('emptyListSchemas')]
    #[Group('ac-04-19')]
    #[Group('ac-04-26')]
    public function testEmptyListSchemasCountAsEmptyObjects(string $input, string $expected, array $outputRegistry, InjectionConfig $config): void
    {
        $result = $this->inject([$this->decode($input)], $config);

        $this->assertSame($this->expand($expected), $this->toolJson($result, 0));
        $this->assertSame($outputRegistry, $result->outputRegistry);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-04-25')]
    public function testNonObjectOutputSchemaFailsOnlyTheHandlePass(): void
    {
        $result = $this->inject([$this->decode('{"name":"o","inputSchema":{"type":"object"},"outputSchema":true}')]);

        $this->assertSame(
            $this->expand('{"name":"o","inputSchema":{"type":"object","properties":{"context":"@CTX"},"required":["context"]},"outputSchema":true}'),
            $this->toolJson($result, 0),
        );
        $this->assertSame(['o' => ['context']], $result->registry);
        $this->assertSame([], $result->outputRegistry);
        $this->assertSame(
            ['WARN: Handle injection failed for tool "o"; listing it unmodified - outputSchema is not a JSON object'],
            $this->logger->messages(),
        );
    }

    #[Group('ac-04-25')]
    public function testNonObjectOutputPropertiesFailTheHandlePass(): void
    {
        $result = $this->inject([$this->decode('{"name":"o","outputSchema":{"properties":"x"}}')], new InjectionConfig(enableReportMissing: false, enableToolCallContext: false));

        $this->assertSame('{"name":"o","outputSchema":{"properties":"x"}}', $this->toolJson($result, 0));
        $this->assertSame(['o' => []], $result->registry);
        $this->assertSame(
            ['WARN: Handle injection failed for tool "o"; listing it unmodified - outputSchema.properties is not a JSON object'],
            $this->logger->messages(),
        );
    }

    #[Group('ac-04-25')]
    public function testHandleFailureRollsBackRegistriesOfTheSameName(): void
    {
        $result = $this->inject(
            [
                $this->decode('{"name":"dup","outputSchema":{"type":"object"}}'),
                $this->decode('{"name":"other","outputSchema":{"type":"object"}}'),
                $this->decode('{"name":"dup","outputSchema":"x"}'),
            ],
            new InjectionConfig(enableReportMissing: false, enableToolCallContext: false),
        );

        $this->assertSame(['other' => ['session_id'], 'dup' => []], $result->registry);
        $this->assertSame(['other'], $result->outputRegistry);
    }

    #[Group('ac-04-25')]
    public function testContextFailureRemovesOnlyContextFromTheSameName(): void
    {
        $result = $this->inject(
            [
                $this->decode('{"name":"dup"}'),
                $this->decode('{"name":"dup","inputSchema":true}'),
            ],
            new InjectionConfig(enableReportMissing: false, enableTracing: false),
        );

        $this->assertSame(['dup' => []], $result->registry);
    }

    public function testInvalidUtf8ToolIsListedUnmodified(): void
    {
        $tool = (object) ['name' => 'bin', 'description' => "\xB1", 'inputSchema' => (object) ['type' => 'object']];
        $before = serialize($tool);

        $result = $this->inject([$tool, $this->decode('{"name":"ok"}')]);

        $this->assertSame($tool, $result->tools[0] ?? null);
        $this->assertSame($before, serialize($tool));
        $this->assertSame($this->expand('{"name":"ok","inputSchema":' . self::INJECTED_ONLY . '}'), $this->toolJson($result, 1));
        $this->assertSame(['ok' => ['session_id', 'context'], 'bin' => []], $result->registry);
        $this->assertSame([
            'WARN: Handle injection failed for tool "bin"; listing it unmodified - the tool cannot be encoded as JSON',
            'WARN: Context injection failed for tool "bin"; listing it unmodified - the tool cannot be encoded as JSON',
        ], $this->logger->messages());
    }

    public function testNumericStringPropertyNamesStayObjectKeys(): void
    {
        $tool = $this->decode('{"name":"n","inputSchema":{"properties":{"0":{"type":"string"}}},"outputSchema":{"properties":{"1":{"type":"number"}}}}');

        $result = $this->inject([$tool]);

        $this->assertSame(
            $this->expand('{"name":"n","inputSchema":{"properties":{"0":{"type":"string"},"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]},"outputSchema":{"properties":{"1":{"type":"number"},"mcp_session":"@MCP"}}}'),
            $this->toolJson($result, 0),
        );
        $this->assertSame(['n'], $result->outputRegistry);
    }

    /**
     * @return iterable<string, array{string, string, InjectionConfig}>
     */
    public static function schemaShapes(): iterable
    {
        $prompted = new InjectionConfig(enableReportMissing: false);
        $contextOnly = new InjectionConfig(enableReportMissing: false, enableTracing: false);

        yield 'missing' => ['{"name":"t"}', '{"name":"t","inputSchema":' . self::INJECTED_ONLY . '}', $prompted];
        yield 'missing, context only' => ['{"name":"t"}', '{"name":"t","inputSchema":{"type":"object","properties":{"context":"@CTX"},"required":["context"]}}', $contextOnly];
        yield 'null keeps its key position' => ['{"name":"t","inputSchema":null,"title":"T"}', '{"name":"t","inputSchema":' . self::INJECTED_ONLY . ',"title":"T"}', $prompted];
        yield 'false' => ['{"name":"t","inputSchema":false}', '{"name":"t","inputSchema":' . self::INJECTED_ONLY . '}', $prompted];
        yield 'empty object gets no type' => ['{"name":"t","inputSchema":{}}', '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]}}', $prompted];
        yield 'empty object, context only' => ['{"name":"t","inputSchema":{}}', '{"name":"t","inputSchema":{"properties":{"context":"@CTX"},"required":["context"]}}', $contextOnly];
        yield 'falsy properties replaced in place' => ['{"name":"t","inputSchema":{"properties":null,"type":"object"}}', '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"type":"object","required":["session_id","context"]}}', $prompted];
        yield 'required not a list' => ['{"name":"t","inputSchema":{"required":"x","properties":{}}}', '{"name":"t","inputSchema":{"required":["session_id","context"],"properties":{"session_id":"@SID","context":"@CTX"}}}', $prompted];
        yield 'required an object' => ['{"name":"t","inputSchema":{"properties":{},"required":{"0":"a"}}}', '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]}}', $prompted];
        yield 'required already lists the names' => ['{"name":"t","inputSchema":{"properties":{},"required":["context","session_id"]}}', '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["context","session_id"]}}', $prompted];
        yield 'additionalProperties true kept' => ['{"name":"t","inputSchema":{"properties":{},"additionalProperties":true}}', '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"additionalProperties":true,"required":["session_id","context"]}}', $prompted];
        yield 'additionalProperties schema kept' => ['{"name":"t","inputSchema":{"properties":{},"additionalProperties":{}}}', '{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"additionalProperties":{},"required":["session_id","context"]}}', $prompted];
        yield 'additionalProperties false removed, context only' => ['{"name":"t","inputSchema":{"additionalProperties":false,"properties":{}}}', '{"name":"t","inputSchema":{"properties":{"context":"@CTX"},"required":["context"]}}', $contextOnly];
    }

    #[DataProvider('schemaShapes')]
    #[Group('ac-04-19')]
    public function testInputSchemaShapes(string $input, string $expected, InjectionConfig $config): void
    {
        $result = $this->inject([$this->decode($input)], $config);

        $this->assertSame($this->expand($expected), $this->toolJson($result, 0));
        $this->assertSame([], $this->logger->messages());
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function contextDescriptions(): iterable
    {
        yield 'null' => [null, Constants::DEFAULT_CONTEXT_PARAMETER_DESCRIPTION];
        yield 'empty string falls back' => ['', Constants::DEFAULT_CONTEXT_PARAMETER_DESCRIPTION];
        yield '"0" is used (JS truthy)' => ['0', '0'];
        yield 'custom, untrimmed' => [' Why? ', ' Why? '];
    }

    #[DataProvider('contextDescriptions')]
    public function testCustomContextDescription(?string $custom, string $expected): void
    {
        $result = $this->inject(
            [$this->decode('{"name":"t","inputSchema":{"properties":{}}}')],
            new InjectionConfig(enableReportMissing: false, enableTracing: false, customContextDescription: $custom),
        );

        $this->assertSame(
            '{"name":"t","inputSchema":{"properties":{"context":' . $this->json((object) ['type' => 'string', 'description' => $expected]) . '},"required":["context"]}}',
            $this->toolJson($result, 0),
        );
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function outputSchemaShapes(): iterable
    {
        yield 'no properties, additionalProperties false kept' => [
            '{"type":"object","additionalProperties":false}',
            '{"type":"object","additionalProperties":false,"properties":{"mcp_session":"@MCP"}}',
            [],
        ];
        yield 'falsy customer mcp_session overwritten' => [
            '{"properties":{"mcp_session":null,"n":{}},"required":["n"]}',
            '{"properties":{"mcp_session":"@MCP","n":{}},"required":["n"]}',
            [],
        ];
        yield 'customer mcp_session' => [
            '{"properties":{"mcp_session":{"type":"object"}}}',
            '{"properties":{"mcp_session":{"type":"object"}}}',
            ['WARN: Tool "o" already declares \'mcp_session\' in outputSchema. Skipping injection.'],
        ];
        yield 'composed' => [
            '{"type":"object","oneOf":[]}',
            '{"type":"object","oneOf":[]}',
            ['WARN: Tool "o" has complex outputSchema (oneOf/allOf/anyOf). Skipping mcp_session injection; mint-back stays content-only for this tool.'],
        ];
        yield 'composed allOf' => [
            '{"allOf":[{}]}',
            '{"allOf":[{}]}',
            ['WARN: Tool "o" has complex outputSchema (oneOf/allOf/anyOf). Skipping mcp_session injection; mint-back stays content-only for this tool.'],
        ];
        yield 'composed anyOf' => [
            '{"anyOf":{}}',
            '{"anyOf":{}}',
            ['WARN: Tool "o" has complex outputSchema (oneOf/allOf/anyOf). Skipping mcp_session injection; mint-back stays content-only for this tool.'],
        ];
        yield 'null' => ['null', 'null', []];
        yield 'false' => ['false', 'false', []];
    }

    /**
     * @param list<string> $logs
     */
    #[DataProvider('outputSchemaShapes')]
    #[Group('ac-04-26')]
    public function testOutputSchemaShapes(string $outputSchema, string $expected, array $logs): void
    {
        $result = $this->inject(
            [$this->decode('{"name":"o","inputSchema":{"properties":{}},"outputSchema":' . $outputSchema . '}')],
            new InjectionConfig(enableReportMissing: false, enableToolCallContext: false),
        );

        $this->assertSame(
            $this->expand('{"name":"o","inputSchema":{"properties":{"session_id":"@SID"},"required":["session_id"]},"outputSchema":' . $expected . '}'),
            $this->toolJson($result, 0),
        );
        $this->assertSame(str_contains($expected, '@MCP') ? ['o'] : [], $result->outputRegistry);
        $this->assertSame($logs, $this->logger->messages());
    }

    public function testOutputRegistryListsEachNameOnce(): void
    {
        $result = $this->inject([
            $this->decode('{"name":"o","outputSchema":{"type":"object"}}'),
            $this->decode('{"name":"o","outputSchema":{"type":"object"}}'),
        ]);

        $this->assertSame(['o'], $result->outputRegistry);
        $this->assertSame(['o' => ['session_id', 'context']], $result->registry);
    }

    #[Group('ac-04-19')]
    public function testAssociativeRequiredArrayIsReplacedLikeAJsonObject(): void
    {
        $tool = (object) ['name' => 't', 'inputSchema' => (object) ['properties' => new \stdClass(), 'required' => ['a' => 'x']]];

        $result = $this->inject([$tool]);

        $this->assertSame(
            $this->expand('{"name":"t","inputSchema":{"properties":{"session_id":"@SID","context":"@CTX"},"required":["session_id","context"]}}'),
            $this->toolJson($result, 0),
        );
    }

    public function testNullItemPassesThroughAndNamelessToolsShareUnknown(): void
    {
        $result = $this->inject([null, $this->decode('{"description":"no name"}'), $this->decode('{"name":""}'), $this->decode('{"name":5}')], new InjectionConfig(enableReportMissing: false, enableToolCallContext: false));

        $this->assertArrayHasKey(0, $result->tools);
        $this->assertNull($result->tools[0]);
        $this->assertSame($this->expand('{"description":"no name","inputSchema":{"type":"object","properties":{"session_id":"@SID"},"required":["session_id"]}}'), $this->toolJson($result, 1));
        $this->assertSame(['unknown' => ['session_id']], $result->registry);
        $this->assertSame(
            ['WARN: Handle injection failed for tool "unknown"; listing it unmodified - the tool is not a JSON object'],
            $this->logger->messages(),
        );
    }

    public function testNullItemAfterNamelessToolClearsTheSharedUnknownEntryLikeTypeScript(): void
    {
        $result = $this->inject([$this->decode('{"description":"no name"}'), null]);

        $this->assertSame(['unknown' => []], $result->registry);
        $this->assertSame([
            'WARN: Handle injection failed for tool "unknown"; listing it unmodified - the tool is not a JSON object',
            'WARN: Context injection failed for tool "unknown"; listing it unmodified - the tool is not a JSON object',
        ], $this->logger->messages());
    }

    public function testNumericStringToolNameIsANormalName(): void
    {
        $result = $this->inject([$this->decode('{"name":"0","outputSchema":{"type":"object"}}')]);

        // PHP turns the numeric-string key into an int; it still encodes as the object key "0".
        $this->assertSame('{"0":["session_id","context"]}', Json::encodeJs((object) $result->registry));
        $this->assertSame(['0'], $result->outputRegistry);
    }

    public function testInputIsNeverMutated(): void
    {
        $fixture = Golden::json('handles/listing-fixtures.json');
        $this->assertInstanceOf(\stdClass::class, $fixture);
        $tools = $fixture->input_tools;
        $this->assertIsArray($tools);
        $tools[] = null;
        $tools[] = $this->decode('{"name":"bad","inputSchema":true,"outputSchema":{"type":"object"}}');
        $tools[] = $this->decode('{"name":"falsy","inputSchema":{"properties":{"session_id":false,"agent_id":null,"context":0},"required":"x","additionalProperties":false}}');
        $tools[] = $this->decode('{"name":"get_more_tools","inputSchema":{"properties":{"q":{}},"required":["q"],"additionalProperties":false}}');
        $tools[] = $this->decode('{"name":"out","inputSchema":{},"outputSchema":{"type":"object","additionalProperties":false}}');
        $tools[] = $this->decode('{"name":"lists","inputSchema":{"properties":[]},"outputSchema":[]}');
        $tools[] = $this->decode('{"name":"list_schema","inputSchema":[],"outputSchema":{"properties":[]}}');
        $this->assertTrue(array_is_list($tools));
        $before = Json::encodeJs($tools);
        $this->assertNotNull($before);

        foreach ([new InjectionConfig(), new InjectionConfig(enableAgentTracking: true), new InjectionConfig(enableTracing: false), new InjectionConfig(enableAgentTracking: true, hookMode: true)] as $config) {
            ToolListInjector::inject($tools, $config, new InjectionState());
            $this->assertSame($before, Json::encodeJs($tools));
        }
    }

    public function testOutputIsIndependentOfInput(): void
    {
        $tool = $this->decode('{"name":"a","inputSchema":{"type":"object","properties":{"x":{"type":"string"}}}}');

        $result = $this->inject([$tool]);
        $listed = $result->tools[0] ?? null;
        $this->assertInstanceOf(\stdClass::class, $listed);
        $this->assertNotSame($tool, $listed);
        $this->assertInstanceOf(\stdClass::class, $listed->inputSchema);
        $this->assertNotSame($tool->inputSchema, $listed->inputSchema);
    }

    public function testWholePipelineFailureServesTheOriginalList(): void
    {
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
        $tools = [$this->decode('{"name":"a"}'), $hostile];

        $result = ToolListInjector::inject($tools, new InjectionConfig(), new InjectionState());

        $this->assertSame($tools, $result->tools);
        $this->assertSame([], $result->registry);
        $this->assertSame([], $result->outputRegistry);
        $this->assertSame(
            ['Warning: AgentCat tool-list injection failed; serving the original tool list unmodified - boom'],
            $this->logger->messages(),
        );
    }

    /**
     * @param list<mixed> $tools
     */
    private function inject(array $tools, InjectionConfig $config = new InjectionConfig(enableReportMissing: false), InjectionState $state = new InjectionState()): InjectedList
    {
        return ToolListInjector::inject($tools, $config, $state);
    }

    private function logCount(string $message): int
    {
        return count(array_filter($this->logger->messages(), static fn(string $logged): bool => $logged === $message));
    }

    private function decode(string $json): \stdClass
    {
        $value = Json::decode($json);
        $this->assertInstanceOf(\stdClass::class, $value);

        return $value;
    }

    private function toolJson(InjectedList $result, int $index): string
    {
        $this->assertArrayHasKey($index, $result->tools);

        return $this->json($result->tools[$index]);
    }

    private function json(mixed $value): string
    {
        $json = Json::encodeJs($value);
        $this->assertNotNull($json);

        return $json;
    }

    /** Replaces "@SID", "@AID", "@CTX" and "@MCP" (prompted mode, no agent tracking) with the injected fragments. */
    private function expand(string $template): string
    {
        $mcpSession = (object) [
            'type' => 'object',
            'description' => Constants::MCP_SESSION_FIELD_DESCRIPTION,
            'properties' => (object) [
                'session_id' => (object) ['type' => 'string', 'description' => Constants::MCP_SESSION_SESSION_ID_DESCRIPTION],
                'status' => (object) ['type' => 'string', 'enum' => ['issued', 'active', 'unrecognized'], 'description' => Constants::MCP_SESSION_STATUS_DESCRIPTION],
            ],
        ];

        return strtr($template, [
            '"@SID"' => $this->json((object) ['type' => 'string', 'description' => Constants::SESSION_ID_PARAM_DESCRIPTION, 'pattern' => Constants::SESSION_ID_PARAM_PATTERN]),
            '"@AID"' => $this->json((object) ['type' => 'string', 'description' => Constants::AGENT_ID_PARAM_DESCRIPTION]),
            '"@CTX"' => $this->json((object) ['type' => 'string', 'description' => Constants::DEFAULT_CONTEXT_PARAMETER_DESCRIPTION]),
            '"@MCP"' => $this->json($mcpSession),
        ]);
    }
}
