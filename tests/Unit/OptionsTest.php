<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Event;
use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Identity;
use AgentCat\Internal\Logging\Log;
use AgentCat\Options;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\ToolCall;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
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

    #[Group('ac-01-13')]
    public function testDefaults(): void
    {
        $options = new Options();

        $this->assertTrue($options->enableReportMissing);
        $this->assertTrue($options->enableTracing);
        $this->assertTrue($options->enableToolCallContext);
        $this->assertFalse($options->enableAgentTracking);
        $this->assertNull($options->customContextDescription);
        $this->assertNull($options->resolveSessionId);
        $this->assertNull($options->identify);
        $this->assertNull($options->redactSensitiveInformation);
        $this->assertNull($options->redactEvent);
        $this->assertNull($options->apiBaseUrl);
        $this->assertNull($options->eventTags);
        $this->assertNull($options->eventProperties);
    }

    public function testNamedArgumentsAreKept(): void
    {
        $options = new Options(enableAgentTracking: true, apiBaseUrl: 'https://a.test');

        $this->assertTrue($options->enableAgentTracking);
        $this->assertSame('https://a.test', $options->apiBaseUrl);
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new \ReflectionClass(Options::class))->isReadOnly());
    }

    public function testFromArrayAcceptsValidValuesAndCallables(): void
    {
        $handler = new class {
            public function __invoke(ToolCall $call): ?Identity
            {
                return $call->toolName === 'known' ? new Identity('u') : null;
            }
        };

        $options = Options::fromArray([
            'enableTracing' => false,
            'customContextDescription' => 'X',
            'identify' => $handler,
        ]);

        $this->assertFalse($options->enableTracing);
        $this->assertSame('X', $options->customContextDescription);
        $this->assertInstanceOf(\Closure::class, $options->identify);
        $this->assertNull(($options->identify)(new ToolCall('t', [])));
        $this->assertEquals(new Identity('u'), ($options->identify)(new ToolCall('known', [])));
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayAcceptsEveryOptionWithAValidValue(): void
    {
        $resolveSessionId = static fn(ToolCall $call): ?string => null;
        $identify = static fn(ToolCall $call): ?Identity => null;
        $redactSensitiveInformation = static fn(string $text): string => $text;
        $redactEvent = static fn(Event $event): Event => $event;
        $eventTags = static fn(ToolCall $call): array => ['k' => 'v'];
        $eventProperties = static fn(ToolCall $call): mixed => ['p' => 1];

        $options = Options::fromArray([
            'enableReportMissing' => false,
            'enableTracing' => false,
            'enableToolCallContext' => false,
            'customContextDescription' => 'Why you call this tool',
            'enableAgentTracking' => true,
            'resolveSessionId' => $resolveSessionId,
            'identify' => $identify,
            'redactSensitiveInformation' => $redactSensitiveInformation,
            'redactEvent' => $redactEvent,
            'apiBaseUrl' => 'https://api.example.test',
            'eventTags' => $eventTags,
            'eventProperties' => $eventProperties,
            'exporters' => [],
            'disableDiagnostics' => true,
            'disableSignalHandlers' => true,
        ]);

        $this->assertFalse($options->enableReportMissing);
        $this->assertFalse($options->enableTracing);
        $this->assertFalse($options->enableToolCallContext);
        $this->assertSame('Why you call this tool', $options->customContextDescription);
        $this->assertTrue($options->enableAgentTracking);
        $this->assertSame($resolveSessionId, $options->resolveSessionId);
        $this->assertSame($identify, $options->identify);
        $this->assertSame($redactSensitiveInformation, $options->redactSensitiveInformation);
        $this->assertSame($redactEvent, $options->redactEvent);
        $this->assertSame('https://api.example.test', $options->apiBaseUrl);
        $this->assertSame($eventTags, $options->eventTags);
        $this->assertSame($eventProperties, $options->eventProperties);
        $this->assertSame([], $options->exporters);
        $this->assertTrue($options->disableDiagnostics);
        $this->assertTrue($options->disableSignalHandlers);
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayKeepsAClosureAsIs(): void
    {
        $closure = static fn(string $text): string => $text;

        $this->assertSame($closure, Options::fromArray(['redactSensitiveInformation' => $closure])->redactSensitiveInformation);
    }

    public function testFromArrayAcceptsExplicitNulls(): void
    {
        $options = Options::fromArray(['apiBaseUrl' => null, 'identify' => null, 'customContextDescription' => null]);

        $this->assertNull($options->apiBaseUrl);
        $this->assertNull($options->identify);
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayWarnsAndUsesDefaults(): void
    {
        $options = Options::fromArray([
            'bogus' => 1,
            'enableAgentTracking' => 'yes',
            'apiBaseUrl' => 5,
            'redactEvent' => 'not a function',
        ]);

        $this->assertFalse($options->enableAgentTracking);
        $this->assertNull($options->apiBaseUrl);
        $this->assertNull($options->redactEvent);
        $this->assertSame([
            'WARN: Unknown AgentCat option "bogus" ignored',
            'WARN: AgentCat option "enableAgentTracking" must be bool, got string; the default is used',
            'WARN: AgentCat option "redactEvent" must be callable or null, got string; the default is used',
            'WARN: AgentCat option "apiBaseUrl" must be string or null, got int; the default is used',
        ], $this->logger->messages());
    }

    public function testFromArrayRejectsAWrongBoolWithItsDefault(): void
    {
        $options = Options::fromArray(['enableTracing' => 0]);

        $this->assertTrue($options->enableTracing);
        $this->assertSame(
            ['WARN: AgentCat option "enableTracing" must be bool, got int; the default is used'],
            $this->logger->messages(),
        );
    }

    public function testFromArrayCastsIntegerKeysToStrings(): void
    {
        Options::fromArray([0 => true]);

        $this->assertSame(['WARN: Unknown AgentCat option "0" ignored'], $this->logger->messages());
    }

    public function testFromArrayWithNoKeysEqualsDefaults(): void
    {
        $this->assertEquals(new Options(), Options::fromArray([]));
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-01-13')]
    public function testTheTelemetryOptionsDefaultToNoneAndOn(): void
    {
        $options = new Options();

        $this->assertNull($options->exporters);
        $this->assertFalse($options->disableDiagnostics);
        $this->assertFalse($options->disableSignalHandlers);
    }

    public function testFromArrayBuildsEveryExporterTypeFromItsTypeScriptKeys(): void
    {
        $options = Options::fromArray([
            'disableDiagnostics' => true,
            'exporters' => [
                'otlp' => ['type' => 'otlp', 'endpoint' => 'http://localhost:4318', 'headers' => ['x-api-key' => 'k']],
                'dd' => ['type' => 'datadog', 'apiKey' => 'ddkey', 'site' => 'datadoghq.eu', 'service' => 'svc', 'env' => 'prod'],
                'sentry' => ['type' => 'sentry', 'dsn' => 'https://abc@o1.ingest.sentry.io/42', 'environment' => 'production', 'release' => '1.2.3', 'enableTracing' => true],
                'ph' => ['type' => 'posthog', 'apiKey' => 'phc_x', 'host' => 'https://eu.i.posthog.com', 'enableAITracing' => true],
                'typed' => new OtlpExporter('http://collector:4318'),
            ],
        ]);

        $this->assertTrue($options->disableDiagnostics);
        $this->assertEquals([
            'otlp' => new OtlpExporter('http://localhost:4318', ['x-api-key' => 'k']),
            'dd' => new DatadogExporter('ddkey', 'datadoghq.eu', 'svc', 'prod'),
            'sentry' => new SentryExporter('https://abc@o1.ingest.sentry.io/42', 'production', '1.2.3', true),
            'ph' => new PostHogExporter('phc_x', 'https://eu.i.posthog.com', true),
            'typed' => new OtlpExporter('http://collector:4318'),
        ], $options->exporters);
        $this->assertSame([], $this->logger->messages());
    }

    #[Group('ac-06-10')]
    public function testFromArraySkipsAnUnknownTypeWithTheTypeScriptLine(): void
    {
        $options = Options::fromArray(['exporters' => ['a' => ['type' => 'otlp', 'endpoint' => 'http://h'], 'b' => ['type' => 'bogus'], 'c' => ['endpoint' => 'x']]]);

        $this->assertSame(['a'], array_keys($options->exporters ?? []));
        $this->assertSame(['Unknown exporter type: bogus', 'Unknown exporter type: undefined'], $this->logger->messages());
    }

    /** Review Focus 2: config files whose env() values are missing or mistyped. */
    public function testFromArraySkipsAnExporterWithMissingOrMistypedValues(): void
    {
        $options = Options::fromArray(['exporters' => [
            'dd' => ['type' => 'datadog', 'apiKey' => null, 'site' => 'datadoghq.com', 'service' => 'svc'],
            'sentry' => ['type' => 'sentry', 'dsn' => 'https://abc@o1.ingest.sentry.io/42', 'enableTracing' => 'yes'],
            'otlp' => ['type' => 'otlp', 'endpoint' => 'http://h', 'headers' => ['x' => 1], 'timeout' => 5],
            'ph' => ['type' => 'posthog', 'apiKey' => 'phc_x', 'hots' => 'typo'],
            'str' => 'otlp',
        ]]);

        $this->assertEquals(['ph' => new PostHogExporter('phc_x')], $options->exporters);
        $this->assertSame([
            'Failed to initialize exporter dd: InvalidArgumentException: exporter option "apiKey" must be string, got null',
            'Failed to initialize exporter sentry: InvalidArgumentException: exporter option "enableTracing" must be bool, got string',
            'WARN: Unknown AgentCat option "exporters.otlp.timeout" ignored',
            'Failed to initialize exporter otlp: InvalidArgumentException: exporter option "headers" must be array<string, string>, got array',
            'WARN: Unknown AgentCat option "exporters.ph.hots" ignored',
            'Failed to initialize exporter str: InvalidArgumentException: exporter configuration must be an array, got string',
        ], $this->logger->messages());
    }

    public function testFromArrayRejectsWrongTelemetryOptionTypes(): void
    {
        $options = Options::fromArray(['exporters' => 'otlp', 'disableDiagnostics' => 'yes', 'disableSignalHandlers' => 1]);

        $this->assertNull($options->exporters);
        $this->assertFalse($options->disableDiagnostics);
        $this->assertFalse($options->disableSignalHandlers);
        $this->assertSame([
            'WARN: AgentCat option "exporters" must be array or null, got string; the default is used',
            'WARN: AgentCat option "disableDiagnostics" must be bool, got string; the default is used',
            'WARN: AgentCat option "disableSignalHandlers" must be bool, got int; the default is used',
        ], $this->logger->messages());
    }

    public function testFromArrayKeepsAnEmptyExporterListAndAnExplicitNull(): void
    {
        $this->assertSame([], Options::fromArray(['exporters' => []])->exporters);
        $this->assertNull(Options::fromArray(['exporters' => null])->exporters);
        $this->assertSame([], $this->logger->messages());
    }

    public function testFromArrayKeepsNumericExporterKeysAsIntegers(): void
    {
        $options = Options::fromArray(['exporters' => [['type' => 'otlp', 'endpoint' => 'x', 'bad' => 1]]]);

        $this->assertEquals([0 => new OtlpExporter('x')], $options->exporters);
        $this->assertSame(['WARN: Unknown AgentCat option "exporters.0.bad" ignored'], $this->logger->messages());
    }
}
