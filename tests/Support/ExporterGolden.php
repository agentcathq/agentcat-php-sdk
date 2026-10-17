<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Json\Json;

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

    /** @return list<array{url: string, headers: \stdClass, body: string}> */
    public static function requests(string $section): array
    {
        $requests = [];
        $current = null;
        foreach (self::section('exporters/golden-output.txt', $section) as $line) {
            if (str_starts_with($line, 'URL POST ')) {
                if ($current !== null) {
                    $requests[] = $current;
                }
                $current = ['url' => substr($line, 9), 'headers' => new \stdClass(), 'body' => ''];
            } elseif ($current !== null && str_starts_with($line, 'HEADERS ')) {
                $headers = Json::decode(substr($line, 8));
                $current['headers'] = $headers instanceof \stdClass ? $headers : new \stdClass();
            } elseif ($current !== null && str_starts_with($line, 'BODY ')) {
                $current['body'] = substr($line, 5);
            } elseif ($current !== null) {
                $current['body'] .= "\n" . $line;
            }
        }
        if ($current !== null) {
            $requests[] = $current;
        }

        return $requests;
    }

    /** @return list<array{url: string, body: string}> */
    public static function requests2(string $section): array
    {
        $requests = [];
        $lines = self::section('exporters/golden2-output.txt', $section);
        foreach ($lines as $index => $line) {
            if (preg_match('~\Ahttps?://\S+ \z~', $line) !== 1) {
                continue;
            }
            $body = [substr($lines[$index + 1] ?? ' ', 1)];
            for ($next = $index + 2; isset($lines[$next]) && preg_match('~\A(https?://|=== |OTLP )~', $lines[$next]) !== 1; ++$next) {
                $body[] = $lines[$next];
            }
            $requests[] = ['url' => rtrim($line), 'body' => implode("\n", $body)];
        }

        return $requests;
    }

    /** Decoded and re-encoded: escaping no longer differs, key order stays. */
    public static function normalized(string $json): string
    {
        return Json::encodeJs(Json::decode($json)) ?? 'unencodable';
    }

    /** @return list<string> the lines of one `=== <name>` section, up to the next section or a TS error line */
    private static function section(string $file, string $name): array
    {
        $lines = [];
        $inside = false;
        foreach (explode("\n", Golden::text($file)) as $line) {
            if (str_starts_with($line, '=== ')) {
                if ($inside) {
                    break;
                }
                $inside = $line === '=== ' . $name;

                continue;
            }
            if ($inside && preg_match('/\A(sentry uppercase key|sentry nonnumeric project|OTLP fractional duration|OTLP 404):/', $line) === 1) {
                break;
            }
            if ($inside) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
