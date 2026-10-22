<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Sentry;

/**
 * A built Sentry transaction: the error event of the same call reuses its trace, op, name and end time.
 *
 * @internal
 */
final readonly class SentryTransaction
{
    public function __construct(
        public string $eventId,
        public string $traceId,
        public string $spanId,
        public string $op,
        public int|float $timestamp,
        public string $name,
        public \stdClass $body,
    ) {}
}
