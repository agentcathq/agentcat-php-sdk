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
    private const string PATTERN = '~\A(https?)://([a-f0-9]+)@([A-Za-z0-9_.-]+)(:[0-9]+)?(/[^\n\r\x{2028}\x{2029}]*)?/([0-9]+)\z~u';

    private function __construct(
        public string $endpoint,
        public string $publicKey,
    ) {}

    /** @throws \InvalidArgumentException `Invalid Sentry DSN: <dsn>`, with the DSN masked (`masked()`) */
    public static function parse(string $dsn): self
    {
        if (preg_match(self::PATTERN, $dsn, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid Sentry DSN: ' . self::masked($dsn));
        }

        // PCRE gives '' for an optional group that did not take part, which is what TypeScript's `|| ""` produces.
        return new self(sprintf('%s://%s%s%s/api/%s/envelope/', $match[1], $match[3], $match[4], $match[5], $match[6]), $match[2]);
    }

    /**
     * `$dsn` with its user-info as `***` (`https://***@host/1`): the public key, and the secret of a legacy
     * `https://public:secret@host/1` DSN, never reach a log line or diagnostics (TypeScript prints the whole DSN). The
     * user-info runs from the scheme's `://` (or the start) to the last `@`, so an `@` in a secret hides nothing;
     * scheme, host, port and path stay. Without an `@`, or with nothing before it, the DSN is returned as is.
     */
    private static function masked(string $dsn): string
    {
        $at = strrpos($dsn, '@');
        if ($at === false) {
            return $dsn;
        }
        $scheme = strpos(substr($dsn, 0, $at), '://');
        $start = $scheme === false ? 0 : $scheme + 3;
        if ($start === $at) {
            return $dsn; // empty user-info: nothing to hide
        }

        return substr($dsn, 0, $start) . '***' . substr($dsn, $at);
    }
}
