<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Exporters\OtlpExporter;
use AgentCat\Identity;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Support\ConfigOptions;
use AgentCat\Laravel\Internal\ServerTracker;
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
        $this->assertSame(['WARN: AgentCat option "identify" must be callable or null, got int; the default is used'], $this->logger->messages());
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
            'enable_tracing' => 'yes',
            'identify' => 'App\Missing\Identify',
            'exporters' => ['otlp' => null, 'dd' => ['type' => 'datadog']],
            'unknown_key' => 1,
        ], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertTrue($options->enableTracing);
        $this->assertSame([], $options->exporters);
        $this->assertSame([
            'WARN: Unknown AgentCat option "agentcat.unknown_key" ignored',
            'WARN: AgentCat option "enableTracing" must be bool, got string; the default is used',
            'Failed to initialize exporter dd: InvalidArgumentException: exporter option "apiKey" must be string, got null',
        ], $logger->messages());
        $this->assertInstanceOf(\Closure::class, $options->identify); // fails when it runs, inside the hook guard (D32)
    }

    public function testAnUnknownKeyLeavesTheKeysAfterIt(): void
    {
        $options = ConfigOptions::options([0 => 'stray', 'enable_tracing' => false], ServerTracker::BRIDGE_KEYS, $this->resolve);

        $this->assertFalse($options->enableTracing);
        $this->assertSame(['WARN: Unknown AgentCat option "agentcat.0" ignored'], $this->logger->messages());
    }
}
