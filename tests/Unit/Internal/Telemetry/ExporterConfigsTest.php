<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Telemetry\ExporterConfigs;
use AgentCat\Tests\Support\MemoryLogger;
use PHPUnit\Framework\TestCase;

final class ExporterConfigsTest extends TestCase
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

    public function testValidEntriesAreKeptInOrderWithTheirKeys(): void
    {
        $typed = new SentryExporter('https://k@h/1');

        $configs = ExporterConfigs::fromArray([
            'b' => ['type' => 'posthog', 'apiKey' => 'k'],
            5 => ['type' => 'datadog', 'apiKey' => 'k', 'site' => 's', 'service' => 'v', 'env' => null],
            'a' => $typed,
            'c' => ['type' => 'otlp', 'endpoint' => 'http://h', 'headers' => ['x' => 'y']],
        ]);

        $this->assertSame(['b', 5, 'a', 'c'], array_keys($configs));
        $this->assertEquals([
            'b' => new PostHogExporter('k'),
            5 => new DatadogExporter('k', 's', 'v'),
            'a' => $typed,
            'c' => new OtlpExporter('http://h', ['x' => 'y']),
        ], $configs);
        $this->assertContains($typed, $configs);
        $this->assertSame([], $this->logger->messages());
    }

    public function testEveryTypedConfigObjectPassesThroughAsIs(): void
    {
        $objects = [
            new OtlpExporter('http://h'),
            new DatadogExporter('k', 's', 'v'),
            new SentryExporter('d'),
            new PostHogExporter('k'),
        ];

        $this->assertSame($objects, ExporterConfigs::fromArray($objects));
        $this->assertSame([], $this->logger->messages());
    }

    public function testEveryHeaderIsKept(): void
    {
        $configs = ExporterConfigs::fromArray(['a' => ['type' => 'otlp', 'endpoint' => 'e', 'headers' => ['x' => '1', 'y' => '2']]]);

        $this->assertEquals(['a' => new OtlpExporter('e', ['x' => '1', 'y' => '2'])], $configs);
    }

    public function testAnEmptyListGivesAnEmptyList(): void
    {
        $this->assertSame([], ExporterConfigs::fromArray([]));
    }

    public function testAnUnknownTypeIsLoggedWithItsValue(): void
    {
        $configs = ExporterConfigs::fromArray(['a' => ['type' => 'bogus'], 'b' => ['type' => 7], 'c' => ['type' => null]]);

        $this->assertSame([], $configs);
        $this->assertSame(['Unknown exporter type: bogus', 'Unknown exporter type: 7', 'Unknown exporter type: null'], $this->logger->messages());
    }

    public function testAMissingTypeIsLoggedAsUndefined(): void
    {
        $this->assertSame([], ExporterConfigs::fromArray(['a' => ['endpoint' => 'x']]));
        $this->assertSame(['Unknown exporter type: undefined'], $this->logger->messages());
    }

    public function testAMistypedFieldSkipsOnlyThatEntry(): void
    {
        $configs = ExporterConfigs::fromArray([
            'a' => ['type' => 'sentry', 'dsn' => 5],
            'b' => ['type' => 'sentry', 'dsn' => 'd', 'release' => 1],
            'c' => ['type' => 'otlp', 'endpoint' => 'e', 'headers' => 'x'],
            'd' => ['type' => 'posthog', 'apiKey' => 'k', 'enableAITracing' => 1],
            'e' => ['type' => 'otlp', 'endpoint' => 'e'],
        ]);

        $this->assertSame(['e'], array_keys($configs));
        $this->assertSame([
            'Failed to initialize exporter a: InvalidArgumentException: exporter option "dsn" must be string, got int',
            'Failed to initialize exporter b: InvalidArgumentException: exporter option "release" must be string or null, got int',
            'Failed to initialize exporter c: InvalidArgumentException: exporter option "headers" must be array<string, string>, got string',
            'Failed to initialize exporter d: InvalidArgumentException: exporter option "enableAITracing" must be bool, got int',
        ], $this->logger->messages());
    }

    public function testANonArrayEntryIsLogged(): void
    {
        $this->assertSame([], ExporterConfigs::fromArray([3 => null, 'x' => new \stdClass()]));
        $this->assertSame([
            'Failed to initialize exporter 3: InvalidArgumentException: exporter configuration must be an array, got null',
            'Failed to initialize exporter x: InvalidArgumentException: exporter configuration must be an array, got stdClass',
        ], $this->logger->messages());
    }

    public function testAnUnknownKeyIsWarnedAboutAndTheEntryIsKept(): void
    {
        $configs = ExporterConfigs::fromArray([
            'a' => ['type' => 'otlp', 'endpoint' => 'e', 'timeout' => 5],
            0 => ['type' => 'posthog', 'apiKey' => 'k', 'dsn' => 'x'],
        ]);

        $this->assertSame(['a', 0], array_keys($configs));
        $this->assertSame([
            'WARN: Unknown AgentCat option "exporters.a.timeout" ignored',
            'WARN: Unknown AgentCat option "exporters.0.dsn" ignored',
        ], $this->logger->messages());
    }

    public function testAHeaderWithANonStringValueIsRejected(): void
    {
        $this->assertSame([], ExporterConfigs::fromArray(['a' => ['type' => 'otlp', 'endpoint' => 'e', 'headers' => ['x' => 'y', 'z' => null]]]));
        $this->assertSame(['Failed to initialize exporter a: InvalidArgumentException: exporter option "headers" must be array<string, string>, got array'], $this->logger->messages());
    }
}
