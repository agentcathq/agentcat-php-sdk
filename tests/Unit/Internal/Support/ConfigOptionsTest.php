<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Exporters\OtlpExporter;
use AgentCat\Identity;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Laravel\Internal\ServerTracker;
use AgentCat\Options;
use AgentCat\Tests\Support\Laravel\ArgumentsHook;
use AgentCat\Tests\Support\Laravel\InvokableIdentify;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Thrown;
use AgentCat\ToolCall;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigOptionsTest extends TestCase
{
    private Container $container;

    /** @var \Closure(string): mixed the hook resolver, as the Laravel bridge passes it */
    private \Closure $resolve;

    private MemoryLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->container = new Container();
        $this->resolve = fn(string $name): mixed => $this->container->make($name);
        $this->logger = new MemoryLogger();
        Log::setLogger($this->logger);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Log::reset();
    }

    public function testEverySnakeCaseKeyBecomesItsOption(): void
    {
        $options = ConfigOptions::options([
            'api_url' => 'http://127.0.0.1:1',
            'enable_report_missing' => false,
            'enable_tracing' => false,
            'enable_tool_call_context' => false,
            'custom_context_description' => 'Why?',
            'enable_agent_tracking' => true,
            'disable_diagnostics' => 'true',
            'disable_signal_handlers' => true,
            'exporters' => ['otlp' => ['type' => 'otlp', 'endpoint' => 'http://127.0.0.1:1/v1/traces']],
        ], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertSame('http://127.0.0.1:1', $options->apiBaseUrl);
        $this->assertSame([false, false, false, 'Why?', true, true, true], [$options->enableReportMissing, $options->enableTracing, $options->enableToolCallContext, $options->customContextDescription, $options->enableAgentTracking, $options->disableDiagnostics, $options->disableSignalHandlers]);
        $this->assertInstanceOf(OtlpExporter::class, $options->exporters['otlp'] ?? null);
        $this->assertSame([], $this->logger->messages());
    }

    public function testAHookIsAnInvokableClassResolvedWhenItRuns(): void
    {
        $container = $this->container;
        $options = ConfigOptions::options(['identify' => InvokableIdentify::class], ServerTracker::BRIDGE_KEYS, $this->resolve);
        $container->instance(InvokableIdentify::class, new InvokableIdentify('from-container'));

        $hook = $options->identify;
        $this->assertInstanceOf(\Closure::class, $hook);
        $this->assertEquals(new Identity('from-container'), $hook(new ToolCall('echo', [])));
    }

    /** @return iterable<string, array{string, string}> */
    public static function hooks(): iterable
    {
        yield 'resolve_session_id' => ['resolve_session_id', 'resolveSessionId'];
        yield 'identify' => ['identify', 'identify'];
        yield 'redact_sensitive_information' => ['redact_sensitive_information', 'redactSensitiveInformation'];
        yield 'redact_event' => ['redact_event', 'redactEvent'];
        yield 'event_tags' => ['event_tags', 'eventTags'];
        yield 'event_properties' => ['event_properties', 'eventProperties'];
    }

    #[DataProvider('hooks')]
    public function testEveryHookTakesAClassNameAndGetsItsArguments(string $key, string $option): void
    {
        $options = ConfigOptions::options([$key => ArgumentsHook::class], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $hook = get_object_vars($options)[$option] ?? null;
        $this->assertInstanceOf(\Closure::class, $hook);
        $this->assertSame(['first', 2], $hook('first', 2));
        $this->assertSame([], $this->logger->messages());
    }

    public function testACallableHookIsKeptAsItIs(): void
    {
        $identify = static fn(ToolCall $call): Identity => new Identity('closure');

        $options = ConfigOptions::options(['identify' => $identify, 'redact_sensitive_information' => 'strtoupper'], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertSame($identify, $options->identify);
        $redact = $options->redactSensitiveInformation;
        $this->assertInstanceOf(\Closure::class, $redact);
        $this->assertSame('SECRET', $redact('secret')); // a function name is no class to resolve
    }

    public function testAHookThatIsNeitherANameNorACallableIsTheDefault(): void
    {
        $options = ConfigOptions::options(['identify' => 7], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertNull($options->identify);
        $this->assertSame(['WARN: AgentCat option "agentcat.identify" must be callable or null, got int; the default is used'], $this->logger->messages());
    }

    public function testAHookWhoseClassIsNotInvokableThrowsWhenItRuns(): void
    {
        $options = ConfigOptions::options(['identify' => \stdClass::class], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $hook = $options->identify;
        $this->assertInstanceOf(\Closure::class, $hook);
        $error = Thrown::by(\UnexpectedValueException::class, static fn(): mixed => $hook(new ToolCall('echo', [])));
        $this->assertSame('stdClass is not invokable', $error->getMessage());
    }

    public function testNullIsTheDefault(): void
    {
        $options = ConfigOptions::options(['enable_tracing' => null, 'identify' => null, 'exporters' => null, 'api_url' => null], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertTrue($options->enableTracing);
        $this->assertNull($options->identify);
        $this->assertNull($options->exporters);
        $this->assertNull($options->apiBaseUrl);
        $this->assertSame([], $this->logger->messages()); // env() of an unset variable is null: no line
    }

    public function testTheBridgeKeysAreNotOptions(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        ConfigOptions::options(['enabled' => true, 'project_id' => 'x', 'exclude' => [], 'projects' => [], 'log_channel' => null], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertSame([], $logger->messages());
    }

    public function testBadValuesLogOneLineEachAndNeverThrow(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $options = ConfigOptions::options([
            'enable_tracing' => 'yes', // a D44 word
            'enable_agent_tracking' => 'maybe',
            'identify' => 'App\Missing\Identify',
            'exporters' => ['otlp' => null, 'dd' => ['type' => 'datadog']],
            'unknown_key' => 1,
        ], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertTrue($options->enableTracing);
        $this->assertFalse($options->enableAgentTracking);
        $this->assertSame([], $options->exporters);
        $this->assertSame([
            'WARN: AgentCat config "agentcat.enable_agent_tracking" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used',
            'WARN: Unknown AgentCat option "agentcat.unknown_key" ignored',
            'Failed to initialize exporter dd: InvalidArgumentException: exporter option "apiKey" must be string, got null',
        ], $logger->messages());
        $this->assertInstanceOf(\Closure::class, $options->identify); // fails when it runs, inside the hook guard (D32)
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function diagnosticsValues(): iterable
    {
        yield 'unset' => [null, false];
        yield 'true' => [true, true];
        yield 'the word off: on' => ['off', false];
        yield 'the file\'s list, both unset' => [[null, null], false];
        yield 'the file\'s list, AGENTCAT_DISABLE_DIAGNOSTICS=true' => [['true', null], true];
        yield 'the file\'s list, DISABLE_DIAGNOSTICS=1' => [[null, '1'], true];
        yield 'off wins: false and yes' => [[false, 'yes'], true];
        yield 'off wins: yes and false' => [['yes', false], true];
        yield 'both false' => [[false, 'no'], false];
    }

    /** D50 in config/agentcat.php: each value can turn diagnostics off, and off wins. */
    #[DataProvider('diagnosticsValues')]
    public function testDisableDiagnosticsTakesOneValueOrAListWhereOffWins(mixed $value, bool $off): void
    {
        $this->assertSame($off, ConfigOptions::diagnosticsOff($value));
        $this->assertSame($off, ConfigOptions::options(['disable_diagnostics' => $value], ServerTracker::BRIDGE_KEYS, $this->resolve)->disableDiagnostics);
        $this->assertSame([], $this->logger->messages());
    }

    /** An opt-out fails closed: an unknown value turns diagnostics off, and the options log it once, by the key's name. */
    public function testAnUnknownDisableDiagnosticsValueTurnsThemOff(): void
    {
        $this->assertTrue(ConfigOptions::diagnosticsOff([null, 'maybe']));
        $this->assertSame([], $this->logger->messages(), 'the provider reads it without a line');

        $this->assertTrue(ConfigOptions::options(['disable_diagnostics' => ['false', 'maybe', [1]]], ServerTracker::BRIDGE_KEYS, $this->resolve)->disableDiagnostics);
        $this->assertSame([
            'WARN: AgentCat config "agentcat.disable_diagnostics" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the value is treated as true',
            'WARN: AgentCat config "agentcat.disable_diagnostics" is "array", not one of true, 1, yes, on, false, 0, no, off; the value is treated as true',
        ], $this->logger->messages());
    }

    /**
     * What `env()` gives a bool key. Laravel turns `true` and `false` into bools; `0`, `1`, `yes`, `on`, `no`
     * and `off` stay strings.
     *
     * @return iterable<string, array{mixed, bool}>
     */
    public static function boolWords(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield '"1"' => ['1', true];
        yield '"0"' => ['0', false];
        yield '"yes"' => ['yes', true];
        yield '"no"' => ['no', false];
        yield '" ON " (trimmed, any case)' => [' ON ', true];
        yield '"Off"' => ['Off', false];
    }

    /** @return iterable<string, array{string, string}> */
    public static function boolKeys(): iterable
    {
        yield 'enable_report_missing' => ['enable_report_missing', 'enableReportMissing'];
        yield 'enable_tracing' => ['enable_tracing', 'enableTracing'];
        yield 'enable_tool_call_context' => ['enable_tool_call_context', 'enableToolCallContext'];
        yield 'enable_agent_tracking' => ['enable_agent_tracking', 'enableAgentTracking'];
        yield 'disable_signal_handlers' => ['disable_signal_handlers', 'disableSignalHandlers'];
    }

    /** Every bool key takes the D44 words, as `enabled` and `disable_diagnostics` do. */
    #[DataProvider('boolWords')]
    public function testEveryBoolKeyTakesTheD44Words(mixed $value, bool $expected): void
    {
        foreach (self::boolKeys() as [$key, $option]) {
            $options = get_object_vars(ConfigOptions::options([$key => $value], ServerTracker::BRIDGE_KEYS, $this->resolve));

            $this->assertSame($expected, $options[$option] ?? null, $key);
        }
        $this->assertSame([], $this->logger->messages());
    }

    /** Any other value of a bool key logs one line that names the key as written, and its default is used. */
    #[DataProvider('boolKeys')]
    public function testABadBoolValueNamesTheKeyAsWrittenAndTheDefaultIsUsed(string $key, string $option): void
    {
        $default = get_object_vars(new Options())[$option] ?? null;

        $options = get_object_vars(ConfigOptions::options([$key => 'maybe'], ServerTracker::BRIDGE_KEYS, $this->resolve));
        $blank = get_object_vars(ConfigOptions::options([$key => ' '], ServerTracker::BRIDGE_KEYS, $this->resolve));
        $other = get_object_vars(ConfigOptions::options([$key => 2.5], ServerTracker::BRIDGE_KEYS, $this->resolve));

        $this->assertIsBool($default);
        $this->assertSame([$default, $default, $default], [$options[$option] ?? null, $blank[$option] ?? null, $other[$option] ?? null]);
        $this->assertSame([
            sprintf('WARN: AgentCat config "agentcat.%s" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used', $key),
            sprintf('WARN: AgentCat config "agentcat.%s" is "2.5", not one of true, 1, yes, on, false, 0, no, off; the default is used', $key),
        ], $this->logger->messages(), 'blank is unset: no line');
    }

    /** A value of another type of any key is named as written, `agentcat.<key>`, and its default is used. */
    public function testAValueOfAnotherTypeNamesTheKeyAsWritten(): void
    {
        $options = ConfigOptions::options([
            'custom_context_description' => 5,
            'api_url' => ['http://127.0.0.1:1'],
            'event_tags' => 1.5,
            'exporters' => 'otlp',
        ], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertSame([null, null, null, null], [$options->customContextDescription, $options->apiBaseUrl, $options->eventTags, $options->exporters]);
        $this->assertSame([
            'WARN: AgentCat option "agentcat.custom_context_description" must be string or null, got int; the default is used',
            'WARN: AgentCat option "agentcat.api_url" must be string or null, got array; the default is used',
            'WARN: AgentCat option "agentcat.event_tags" must be callable or null, got float; the default is used',
            'WARN: AgentCat option "agentcat.exporters" must be array or null, got string; the default is used',
        ], $this->logger->messages());
    }

    public function testAnUnknownKeyLeavesTheKeysAfterIt(): void
    {
        $options = ConfigOptions::options([0 => 'stray', 'enable_tracing' => false], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertFalse($options->enableTracing);
        $this->assertSame(['WARN: Unknown AgentCat option "agentcat.0" ignored'], $this->logger->messages());
    }
}
