<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Exporters\DatadogExporter;
use AgentCat\Exporters\OtlpExporter;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Telemetry\Datadog\DatadogPayload;
use AgentCat\Internal\Telemetry\Otlp\OtlpPayload;
use AgentCat\Tests\Support\ExporterFixtures;
use AgentCat\Tests\Support\ExporterGolden;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ExporterGoldenTest extends TestCase
{
    #[Group('ac-06-18')]
    public function testOtlpSuccessMatchesTheGoldenRequest(): void
    {
        $golden = ExporterGolden::requests('otlp ok')[0] ?? throw new \LogicException('golden request missing');
        $request = OtlpPayload::of(new OtlpExporter('http://localhost:4318/', ['x-api-key' => 'k']))->request(ExporterFixtures::event());

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(get_object_vars($golden['headers']), $request->headers);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-20')]
    public function testOtlpErrorMatchesTheGoldenRequest(): void
    {
        $golden = ExporterGolden::requests('otlp err')[0] ?? throw new \LogicException('golden request missing');
        $request = OtlpPayload::of(new OtlpExporter('http://localhost:4318/v1/traces'))->request(ExporterFixtures::errorEvent());

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame(ExporterGolden::normalized($golden['body']), ExporterGolden::normalized($request->body));
    }

    #[Group('ac-06-25')]
    public function testDatadogSuccessMatchesTheGoldenRequests(): void
    {
        $golden = ExporterGolden::requests('datadog ok');
        $this->assertCount(2, $golden);
        $requests = DatadogPayload::of(new DatadogExporter('ddkey', 'https://datadoghq.eu/', 'svc', 'prod'))->requests(ExporterFixtures::event());

        $this->assertSame([$golden[0]['url'], $golden[1]['url']], [$requests['logs']->url, $requests['metrics']->url]);
        $this->assertSame(get_object_vars($golden[0]['headers']), $requests['logs']->headers);
        $this->assertSame(get_object_vars($golden[1]['headers']), $requests['metrics']->headers);
        $this->assertSame(ExporterGolden::normalized($golden[0]['body']), ExporterGolden::normalized($requests['logs']->body));
        $this->assertSame(ExporterGolden::normalized($golden[1]['body']), ExporterGolden::normalized($requests['metrics']->body));
        $this->assertSame(2, $requests['series']);
    }

    #[Group('ac-06-26')]
    public function testDatadogErrorMatchesTheGoldenRequests(): void
    {
        $golden = ExporterGolden::requests('datadog err');
        $this->assertCount(2, $golden);
        $requests = DatadogPayload::of(new DatadogExporter('ddkey', 'datadoghq.com', 'svc'))->requests(ExporterFixtures::errorEvent());

        $this->assertSame(ExporterGolden::normalized($golden[0]['body']), ExporterGolden::normalized($requests['logs']->body));
        $this->assertSame(ExporterGolden::normalized($golden[1]['body']), ExporterGolden::normalized($requests['metrics']->body));
        $this->assertSame(3, $requests['series']);
    }

    #[Group('ac-06-29')]
    public function testASessionlessOtlpEventMatchesTheGoldenRequestExceptTheRandomTraceId(): void
    {
        $golden = ExporterGolden::requests2('otlp')[0] ?? throw new \LogicException('golden request missing');
        $request = OtlpPayload::of(new OtlpExporter('http://c:4318'))->request(ExporterFixtures::listEvent());

        $this->assertSame($golden['url'], $request->url);
        $this->assertSame($this->withoutTrace($golden['body']), $this->withoutTrace($request->body));
        $this->assertSame(1, preg_match('/"traceId":"[0-9a-f]{32}"/', $request->body));
    }

    #[Group('ac-06-29')]
    public function testASessionlessDatadogEventMatchesTheGoldenRequestsExceptTheRandomTraceId(): void
    {
        $golden = ExporterGolden::requests2('dd');
        $this->assertCount(2, $golden);
        $requests = DatadogPayload::of(new DatadogExporter('ddkey', 'us3.datadoghq.com', 'svc'))->requests(ExporterFixtures::listEvent());

        $this->assertSame([$golden[0]['url'], $golden[1]['url']], [$requests['logs']->url, $requests['metrics']->url]);
        $this->assertSame($this->withoutTrace($golden[0]['body']), $this->withoutTrace($requests['logs']->body));
        $this->assertSame(ExporterGolden::normalized($golden[1]['body']), ExporterGolden::normalized($requests['metrics']->body));
        $this->assertSame(1, preg_match('/"trace_id":"[0-9]{1,20}"/', $requests['logs']->body));
    }

    private function withoutTrace(string $json): string
    {
        $tree = Json::decode($json);
        $tree = self::replace($tree);

        return ExporterGolden::normalized(Json::encodeJs($tree) ?? '');
    }

    private static function replace(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(self::replace(...), $node);
        }
        if ($node instanceof \stdClass) {
            foreach (get_object_vars($node) as $key => $child) {
                $node->{$key} = ($key === 'traceId' || $key === 'trace_id') ? 'X' : self::replace($child);
            }
        }

        return $node;
    }
}
