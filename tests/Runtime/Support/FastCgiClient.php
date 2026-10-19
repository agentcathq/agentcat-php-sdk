<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime\Support;

/**
 * A minimal FastCGI 1.0 client, one request per connection: it talks to PHP-FPM the way nginx does, and reports when
 * FPM ended the request (`FCGI_END_REQUEST`), which is when FPM is done with the client.
 */
final readonly class FastCgiClient
{
    private const int VERSION = 1;

    private const int REQUEST_ID = 1;

    private const int BEGIN_REQUEST = 1;

    private const int END_REQUEST = 3;

    private const int PARAMS = 4;

    private const int STDIN = 5;

    private const int STDOUT = 6;

    private const int RESPONDER = 1;

    /** The largest content of one record. */
    private const int MAX_CONTENT = 65535;

    private const int HEADER_BYTES = 8;

    /** The longest timeout a caller may give. */
    private const float MAX_SECONDS = 600.0;

    public function __construct(private string $host, private int $port) {}

    /**
     * Sends one request and reads the whole answer, within `$timeout` seconds in all.
     *
     * @param string                $script  `SCRIPT_FILENAME`: the script's path in the container
     * @param array<string, string> $headers request headers, sent as `HTTP_*` parameters (`Content-Type` as `CONTENT_TYPE`)
     *
     * @return array{status: int, headers: array<string, string>, body: string, endedAt: float} lower-case header names;
     *                                                                                        `endedAt`: when `FCGI_END_REQUEST` arrived
     */
    public function request(string $script, string $method, string $uri, array $headers, string $body, float $timeout = 10.0): array
    {
        if (!is_finite($timeout) || $timeout <= 0.0 || $timeout > self::MAX_SECONDS) {
            throw new \InvalidArgumentException(sprintf('A FastCGI timeout must be above 0 and at most %d s: %s', (int) self::MAX_SECONDS, var_export($timeout, true)));
        }
        $deadline = microtime(true) + $timeout;
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException('FastCGI: ' . $message);
        });

        try {
            $socket = stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $code, $message, $timeout);
            if ($socket === false) {
                throw new \RuntimeException(sprintf('FastCGI: cannot connect to %s:%d: %s', $this->host, $this->port, $message));
            }

            try {
                $this->write($socket, $this->records($script, $method, $uri, $headers, $body), $deadline);
                [$stdout, $endedAt] = $this->readAnswer($socket, $deadline);
            } finally {
                fclose($socket);
            }
        } finally {
            restore_error_handler();
        }

        return $this->answer($stdout, $endedAt);
    }

    /**
     * The request's records: `FCGI_BEGIN_REQUEST`, the parameters and the body, each stream ended by an empty record.
     *
     * @param array<string, string> $headers
     */
    private function records(string $script, string $method, string $uri, array $headers, string $body): string
    {
        $path = strstr($uri, '?', true);
        $params = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => $path === false ? $uri : $path,
            'QUERY_STRING' => $path === false ? '' : substr($uri, strlen($path) + 1),
            'SCRIPT_FILENAME' => $script,
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($body),
        ];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            if ($key === 'CONTENT_TYPE') {
                $params['CONTENT_TYPE'] = $value; // nginx sends it without the HTTP_ prefix
            } elseif ($key !== 'CONTENT_LENGTH') {
                $params['HTTP_' . $key] = $value;
            }
        }
        // PHP-FPM parses the pairs of each record on their own: a pair never spans two records.
        $records = '';
        $pairs = '';
        foreach ($params as $name => $value) {
            $pair = $this->length(strlen($name)) . $this->length(strlen($value)) . $name . $value;
            if (strlen($pair) > self::MAX_CONTENT) {
                throw new \InvalidArgumentException(sprintf('FastCGI: the parameter %s does not fit in one record', $name));
            }
            if (strlen($pairs) + strlen($pair) > self::MAX_CONTENT) {
                $records .= $this->record(self::PARAMS, $pairs);
                $pairs = '';
            }
            $pairs .= $pair;
        }
        $records .= $this->record(self::PARAMS, $pairs) . $this->record(self::PARAMS, '');
        for ($offset = 0; $offset < strlen($body); $offset += self::MAX_CONTENT) {
            $records .= $this->record(self::STDIN, substr($body, $offset, self::MAX_CONTENT));
        }

        return $this->record(self::BEGIN_REQUEST, pack('nCx5', self::RESPONDER, 0)) // flags 0: FPM closes the connection
            . $records
            . $this->record(self::STDIN, '');
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCx', self::VERSION, $type, self::REQUEST_ID, strlen($content), 0) . $content;
    }

    /** @param int<0, max> $length */
    private function length(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    /**
     * Reads records up to `FCGI_END_REQUEST`; keeps `FCGI_STDOUT` and drops the others (`FCGI_STDERR`).
     *
     * @param resource $socket
     *
     * @return array{string, float} stdout, and when `FCGI_END_REQUEST` arrived
     */
    private function readAnswer(mixed $socket, float $deadline): array
    {
        $stdout = '';
        while (true) {
            $header = unpack('Cversion/Ctype/nid/nlength/Cpadding', $this->read($socket, self::HEADER_BYTES, $deadline));
            $type = is_array($header) ? $header['type'] ?? null : null;
            $length = is_array($header) ? $header['length'] ?? null : null;
            $padding = is_array($header) ? $header['padding'] ?? null : null;
            if (!is_int($type) || !is_int($length) || !is_int($padding)) {
                throw new \RuntimeException('FastCGI: a bad record header');
            }
            $content = $this->read($socket, $length, $deadline);
            $this->read($socket, $padding, $deadline);
            if ($type === self::END_REQUEST) {
                return [$stdout, microtime(true)];
            }
            if ($type === self::STDOUT) {
                $stdout .= $content;
            }
        }
    }

    /** @return array{status: int, headers: array<string, string>, body: string, endedAt: float} */
    private function answer(string $stdout, float $endedAt): array
    {
        [$head, $body] = explode("\r\n\r\n", $stdout, 2) + ['', ''];
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
            }
        }
        $status = preg_match('/\A(\d{3})\b/', $headers['status'] ?? '200', $match) === 1 ? (int) $match[1] : 0;

        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'endedAt' => $endedAt];
    }

    /** @param resource $socket */
    private function write(mixed $socket, string $data, float $deadline): void
    {
        while ($data !== '') {
            $this->waitFor($socket, $deadline, strlen($data) . ' bytes unsent');
            $written = fwrite($socket, $data);
            if ($written === false) {
                throw new \RuntimeException('FastCGI: the write failed');
            }
            $data = substr($data, $written);
        }
    }

    /** @param resource $socket */
    private function read(mixed $socket, int $bytes, float $deadline): string
    {
        $data = '';
        while (($missing = $bytes - strlen($data)) > 0) {
            $this->waitFor($socket, $deadline, sprintf('%d of %d bytes read', strlen($data), $bytes));
            $chunk = fread($socket, $missing);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                throw new \RuntimeException(sprintf('FastCGI: the connection ended after %d of %d bytes', strlen($data), $bytes));
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Gives the next socket operation the time left until the deadline.
     *
     * @param resource $socket
     */
    private function waitFor(mixed $socket, float $deadline, string $progress): void
    {
        $left = $deadline - microtime(true);
        if ($left <= 0.0) {
            throw new \RuntimeException('FastCGI: no complete answer within the timeout (' . $progress . ')');
        }
        $seconds = (int) $left; // finite and at most MAX_SECONDS: request() checked the timeout
        stream_set_timeout($socket, $seconds, (int) (($left - $seconds) * 1_000_000));
    }
}
