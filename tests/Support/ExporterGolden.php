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

    /**
     * The `=== diagnostics` section of `golden2-output.txt`: its first line is `<url> <headers JSON> `, the rest is the
     * pretty-printed body.
     *
     * @return array{url: string, headers: \stdClass, body: \stdClass}
     */
    public static function diagnostics(): array
    {
        $lines = self::section('exporters/golden2-output.txt', 'diagnostics');
        [$url, $headers] = explode(' ', array_shift($lines) ?? '', 2) + ['', ''];
        $headers = Json::decode(trim($headers));
        $body = Json::decode(implode("\n", $lines));
        if (!$headers instanceof \stdClass || !$body instanceof \stdClass) {
            throw new \RuntimeException('The golden diagnostics section has no headers or no body');
        }

        return ['url' => $url, 'headers' => $headers, 'body' => $body];
    }

    /** Decoded and re-encoded: escaping no longer differs, key order stays. */
    public static function normalized(string $json): string
    {
        return Json::encodeJs(Json::decode($json)) ?? 'unencodable';
    }

    /**
     * A Sentry envelope with the volatile parts fixed: every JSON line decoded and re-encoded, `sent_at` is `X`, and in
     * transaction and error envelopes every `event_id` keeps its first 16 hex digits (the rest is random) and ends in `R`.
     */
    public static function maskSentry(string $envelope): string
    {
        $lines = explode("\n", $envelope);
        $item = Json::decode($lines[1] ?? '{}');
        $random = $item instanceof \stdClass && in_array($item->type ?? null, ['transaction', 'event'], true);
        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }
            $decoded = Json::decode($line);
            if ($decoded instanceof \stdClass) {
                if (isset($decoded->sent_at)) {
                    $decoded->sent_at = 'X';
                }
                if ($random && is_string($decoded->event_id ?? null)) {
                    $decoded->event_id = substr($decoded->event_id, 0, 16) . 'R';
                }
            }
            $lines[$index] = Json::encodeJs($decoded) ?? 'unencodable';
        }

        return implode("\n", $lines);
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
