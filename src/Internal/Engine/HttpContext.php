<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * The HTTP request of the current PHP request, read from `$_SERVER`. Null on the command line (stdio servers).
 *
 * @internal
 */
final readonly class HttpContext
{
    /**
     * @param array<string, string> $headers lower-case dashed names, values verbatim (D06)
     */
    public function __construct(
        public ?string $method,
        public ?string $url,
        public array $headers,
    ) {}

    public static function capture(): ?self
    {
        return self::fromServer(PHP_SAPI, $_SERVER);
    }

    /**
     * @param array<array-key, mixed> $server
     */
    public static function fromServer(string $sapi, array $server): ?self
    {
        if ($sapi === 'cli' || $sapi === 'phpdbg') {
            return null;
        }
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        $method = is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : null;
        if ($method === null && $headers === []) {
            return null;
        }

        return new self($method, self::url($server), $headers);
    }

    /**
     * The projection TypeScript v1 calls `requestInfo` (D34): `{headers, url?}`. `method` stays internal.
     */
    public function toRequestInfo(): \stdClass
    {
        $info = new \stdClass();
        $info->headers = (object) $this->headers;
        if ($this->url !== null) {
            $info->url = $this->url;
        }

        return $info;
    }

    /** @param array<array-key, mixed> $server */
    private static function url(array $server): ?string
    {
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '') {
            return null; // no host, no full URL (C15)
        }
        $https = $server['HTTPS'] ?? null;
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $uri = $server['REQUEST_URI'] ?? '';

        return $scheme . '://' . $host . (is_string($uri) ? $uri : '');
    }
}
