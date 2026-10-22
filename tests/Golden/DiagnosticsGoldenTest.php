<?php

declare(strict_types=1);

namespace AgentCat\Tests\Golden;

use AgentCat\Internal\Constants;
use AgentCat\Internal\Diagnostics\DiagnosticsPayload;
use AgentCat\Internal\Diagnostics\Severity;
use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Support\ExporterGolden;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The TypeScript diagnostics request of `golden2-output.txt`. */
final class DiagnosticsGoldenTest extends TestCase
{
    #[Group('ac-06-54')]
    public function testThePayloadMatchesTheGoldenBody(): void
    {
        $golden = ExporterGolden::diagnostics()['body'];
        $resource = [];
        foreach ($this->list(JsonAssert::at($golden, 'resourceLogs', 0, 'resource', 'attributes')) as $attribute) {
            $key = JsonAssert::at($attribute, 'key');
            $value = JsonAssert::at($attribute, 'value', 'stringValue');
            $this->assertIsString($key);
            $this->assertIsString($value);
            $resource[] = ['key' => $key, 'value' => ['stringValue' => $value]];
        }
        $records = [];
        foreach ($this->list(JsonAssert::at($golden, 'resourceLogs', 0, 'scopeLogs', 0, 'logRecords')) as $record) {
            $records[] = [
                'timeUnixNano' => JsonAssert::at($record, 'timeUnixNano'),
                'severityNumber' => JsonAssert::at($record, 'severityNumber'),
                'severityText' => JsonAssert::at($record, 'severityText'),
                'body' => JsonAssert::at($record, 'body'),
                'attributes' => JsonAssert::at($record, 'attributes'),
            ];
        }

        $body = DiagnosticsPayload::encode($resource, $records, '2.1.1-beta.1');

        $this->assertSame(Json::encodeJs($golden), ExporterGolden::normalized($body));
    }

    #[Group('ac-06-54')]
    public function testTheGoldenRequestGoesToTheDefaultCollectorWithTheDefaultToken(): void
    {
        $golden = ExporterGolden::diagnostics();

        $this->assertSame(Constants::DEFAULT_DIAGNOSTICS_ENDPOINT . '/v1/logs', $golden['url']);
        $this->assertSame(['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . Constants::DEFAULT_DIAGNOSTICS_TOKEN], get_object_vars($golden['headers']));
        $this->assertSame(Constants::DIAGNOSTICS_SCOPE_NAME, JsonAssert::at($golden['body'], 'resourceLogs', 0, 'scopeLogs', 0, 'scope', 'name'));
    }

    #[Group('ac-06-57')]
    public function testEveryGoldenSeverityIsTheSeverityOfItsLine(): void
    {
        $records = $this->list(JsonAssert::at(ExporterGolden::diagnostics()['body'], 'resourceLogs', 0, 'scopeLogs', 0, 'logRecords'));
        $this->assertNotSame([], $records);
        $texts = [];
        foreach ($records as $record) {
            $line = JsonAssert::at($record, 'body', 'stringValue');
            $this->assertIsString($line);
            $this->assertSame(Severity::of($line), [JsonAssert::at($record, 'severityNumber'), JsonAssert::at($record, 'severityText')]);
            $texts[] = JsonAssert::at($record, 'severityText');
        }
        $this->assertContains('WARN', $texts);
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        $this->assertIsList($value);

        return $value;
    }
}
