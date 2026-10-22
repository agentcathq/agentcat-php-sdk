<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry;

/**
 * TypeScript `traceContext`: one trace per session, one span per event, no parent; random IDs for empty input.
 *
 * @internal
 */
final class TraceContext
{
    public static function traceId(string $sessionId): string
    {
        return $sessionId === '' ? bin2hex(random_bytes(16)) : substr(hash('sha256', $sessionId), 0, 32);
    }

    public static function spanId(string $eventId): string
    {
        return $eventId === '' ? bin2hex(random_bytes(8)) : substr(hash('sha256', $eventId), 0, 16);
    }

    /** The low 64 bits of the trace ID (Datadog's OTel 128 → 64-bit rule), so OTLP traces and Datadog logs correlate. */
    public static function datadogTraceId(string $sessionId): string
    {
        return self::unsignedDecimal(substr(self::traceId($sessionId), 16));
    }

    public static function datadogSpanId(string $eventId): string
    {
        return self::unsignedDecimal(self::spanId($eventId));
    }

    /** 16 hex digits as an unsigned 64-bit decimal. Never `hexdec()`: it returns a float above PHP_INT_MAX. */
    public static function unsignedDecimal(string $hex16): string
    {
        /** @var array{1: int} $word */
        $word = unpack('J', (string) hex2bin($hex16));

        return sprintf('%u', $word[1]);
    }
}
