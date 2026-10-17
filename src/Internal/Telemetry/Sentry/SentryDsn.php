<?php

declare(strict_types=1);

namespace AgentCat\Internal\Telemetry\Sentry;

/**
 * TypeScript `parseDSN`: lower-case hex public key only, no `:secret`, numeric project ID last. A path may not contain
 * the characters JavaScript's `.` skips.
 *
 * @internal
 */
final readonly class SentryDsn
{
    private const string PATTERN = '~\A(https?)://([a-f0-9]+)@([\w.-]+)(:\d+)?(/[^\n\r\x{2028}\x{2029}]*)?/(\d+)\z~u';

    private function __construct(
        public string $endpoint,
        public string $publicKey,
    ) {}

    /** @throws \InvalidArgumentException */
    public static function parse(string $dsn): self
    {
        if (preg_match(self::PATTERN, $dsn, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid Sentry DSN: ' . $dsn);
        }

        // PCRE gives '' for an optional group that did not take part, which is what TypeScript's `|| ""` produces.
        return new self(sprintf('%s://%s%s%s/api/%s/envelope/', $match[1], $match[3], $match[4], $match[5], $match[6]), $match[2]);
    }
}
