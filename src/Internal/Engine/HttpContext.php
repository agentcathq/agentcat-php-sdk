<?php

declare(strict_types=1);

namespace AgentCat\Internal\Engine;

/**
 * The HTTP request of an MCP call: read from `$_SERVER` (null on the command line, stdio servers), or from the request
 * object a framework bridge hands over, so a worker that serves many requests in one process records each one's own.
 *
 * @internal
 */
final readonly class HttpContext
{
    /**
     * The headers that Symfony puts in a request's header bag and no client sent. `ServerBag` makes up the `php-auth-*`
     * headers from the `Authorization` header or the SAPI's `PHP_AUTH_*` variables; two hold the credentials in clear
     * text, and the `Authorization` header stays (D06). `HttpKernel::handle()` adds `x-php-ob-level`.
     */
    private const array MADE_UP_HEADERS = ['php-auth-user', 'php-auth-pw', 'php-auth-digest', 'x-php-ob-level'];

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
     * The request object of a framework (Symfony's `Request`, which Laravel's extends), from its plain values, with the
     * URL and the headers as the client sent them, like the SAPI reading of `fromServer()`:
     * - the URL from the scheme `HTTPS` gives, the `Host` header and the raw request URI, through `url()`: Laravel's
     *   `fullUrl()` sorts the query and drops a trailing slash, and Symfony's `getUri()` sorts the query and refuses
     *   an invalid host. The `Host` header, not the server variable: a request that a worker builds from PSR-7 has
     *   only the header;
     * - the header names as the header bag keeps them (lower case); the values of a repeated header joined with `, `,
     *   like a SAPI joins them, a null value as `''`; the headers that Symfony makes up left out: `php-auth-*` from the
     *   credentials (`ServerBag`), and `x-php-ob-level`, which `HttpKernel::handle()` adds to each request it handles.
     *
     * @param array<string, list<string|null>> $headers    the request's header bag (`HeaderBag::all()`)
     * @param mixed                            $https      the `HTTPS` server variable
     * @param mixed                            $requestUri the `REQUEST_URI` server variable
     */
    public static function fromRequest(string $method, array $headers, mixed $https, ?string $host, mixed $requestUri): self
    {
        $values = [];
        foreach ($headers as $name => $list) {
            if (!in_array($name, self::MADE_UP_HEADERS, true)) {
                $values[$name] = implode(', ', $list);
            }
        }

        return new self($method, self::url(['HTTPS' => $https, 'HTTP_HOST' => $host, 'REQUEST_URI' => $requestUri]), $values);
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

    /**
     * The full URL as a web server passes it to PHP: the scheme `HTTPS` gives, the `HTTP_HOST` and the raw
     * `REQUEST_URI`, query and trailing slash as sent. Null without a host: no URL is made up.
     *
     * @param array<array-key, mixed> $server
     */
    private static function url(array $server): ?string
    {
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($host) || $host === '') {
            return null; // no host, no full URL
        }
        $https = $server['HTTPS'] ?? null;
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $uri = $server['REQUEST_URI'] ?? '';

        return $scheme . '://' . $host . (is_string($uri) ? $uri : '');
    }
}
