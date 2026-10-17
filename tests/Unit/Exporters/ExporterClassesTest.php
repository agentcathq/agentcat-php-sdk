<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Exporters;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Exporters\PostHogExporter;
use AgentCat\Exporters\SentryExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExporterClassesTest extends TestCase
{
    public function testOtlpKeepsItsNamedArgumentsAndDefaults(): void
    {
        $exporter = new OtlpExporter(endpoint: 'http://h', headers: ['x' => 'y']);

        $this->assertSame('http://h', $exporter->endpoint);
        $this->assertSame(['x' => 'y'], $exporter->headers);
        $this->assertSame([], (new OtlpExporter('http://h'))->headers);
    }

    public function testDatadogKeepsItsNamedArgumentsAndDefaults(): void
    {
        $exporter = new DatadogExporter(apiKey: 'k', site: 'datadoghq.eu', service: 'svc', env: 'prod');

        $this->assertSame(['k', 'datadoghq.eu', 'svc', 'prod'], [$exporter->apiKey, $exporter->site, $exporter->service, $exporter->env]);
        $this->assertNull((new DatadogExporter('k', 's', 'v'))->env);
    }

    public function testSentryKeepsItsNamedArgumentsAndDefaults(): void
    {
        $exporter = new SentryExporter(dsn: 'd', environment: 'e', release: 'r', enableTracing: true);

        $this->assertSame(['d', 'e', 'r', true], [$exporter->dsn, $exporter->environment, $exporter->release, $exporter->enableTracing]);

        $default = new SentryExporter('d');
        $this->assertNull($default->environment);
        $this->assertNull($default->release);
        $this->assertFalse($default->enableTracing);
    }

    public function testPostHogKeepsItsNamedArgumentsAndDefaults(): void
    {
        $exporter = new PostHogExporter(apiKey: 'k', host: 'https://h', enableAITracing: true);

        $this->assertSame(['k', 'https://h', true], [$exporter->apiKey, $exporter->host, $exporter->enableAITracing]);

        $default = new PostHogExporter('k');
        $this->assertNull($default->host);
        $this->assertFalse($default->enableAITracing);
    }

    /** @param class-string $class */
    #[DataProvider('classes')]
    public function testEveryClassIsFinalAndReadonly(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    /** @return \Iterator<string, array{class-string}> */
    public static function classes(): \Iterator
    {
        yield 'otlp' => [OtlpExporter::class];
        yield 'datadog' => [DatadogExporter::class];
        yield 'sentry' => [SentryExporter::class];
        yield 'posthog' => [PostHogExporter::class];
    }

    public function testAnInvalidDsnConstructsWithoutAnError(): void
    {
        $this->assertSame('not a dsn', (new SentryExporter('not a dsn'))->dsn);
    }
}
