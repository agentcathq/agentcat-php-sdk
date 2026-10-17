<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/** Reads `tests/Fixtures/golden/exporters/golden-output.txt` and `golden2-output.txt`. */
final class ExporterGolden
{
    /**
     * Label => value, from the lines before the first `=== ` section.
     *
     * @return array{sessionId: string, eventId: string, traceId: string, spanId: string, ddTrace: string, ddSpan: string, 'uuid(session)': string, 'uuid(event)': string, 'uuid(my-customer-task-id)': string, "uuid('')": string, 'uuid(ses_session45600000000000000000)': string, 'uuid(abc_def_ghi)': string, 'uuid(27 dashes)': string}
     */
    public static function ids(): array
    {
        $header = strstr(Golden::text('exporters/golden-output.txt'), '=== ', true);
        preg_match_all("/(sessionId|eventId|traceId|spanId|ddTrace|ddSpan|uuid\\([^)]*\\)) (\\S+)/", (string) $header, $matches, PREG_SET_ORDER);
        $ids = [];
        foreach ($matches as $match) {
            $ids[$match[1]] = $match[2];
        }

        return [
            'sessionId' => self::value($ids, 'sessionId'),
            'eventId' => self::value($ids, 'eventId'),
            'traceId' => self::value($ids, 'traceId'),
            'spanId' => self::value($ids, 'spanId'),
            'ddTrace' => self::value($ids, 'ddTrace'),
            'ddSpan' => self::value($ids, 'ddSpan'),
            'uuid(session)' => self::value($ids, 'uuid(session)'),
            'uuid(event)' => self::value($ids, 'uuid(event)'),
            'uuid(my-customer-task-id)' => self::value($ids, 'uuid(my-customer-task-id)'),
            "uuid('')" => self::value($ids, "uuid('')"),
            'uuid(ses_session45600000000000000000)' => self::value($ids, 'uuid(ses_session45600000000000000000)'),
            'uuid(abc_def_ghi)' => self::value($ids, 'uuid(abc_def_ghi)'),
            'uuid(27 dashes)' => self::value($ids, 'uuid(27 dashes)'),
        ];
    }

    /** @param array<string, string> $ids */
    private static function value(array $ids, string $label): string
    {
        return $ids[$label] ?? throw new \RuntimeException('The golden header has no ' . $label . ' line');
    }
}
