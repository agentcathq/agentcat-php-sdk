<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/** The GET of the `tests/bin` scripts that read GitHub and Packagist. */
final class HttpGet
{
    /**
     * The body of a GET that answered 200; a RuntimeException when the request fails or answers another status. The
     * user agent names the script.
     */
    public static function body(string $url, string $script, string ...$headers): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('GET ' . $url . ': curl_init() failed');
        }
        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_CONNECTTIMEOUT => 10,
            \CURLOPT_TIMEOUT => 30,
            \CURLOPT_USERAGENT => 'agentcat-php-sdk ' . $script,
            \CURLOPT_HTTPHEADER => array_values($headers),
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        if (!is_string($body) || $status !== 200) {
            throw new \RuntimeException(sprintf('GET %s: %s', $url, $error !== '' ? $error : 'HTTP ' . $status));
        }

        return $body;
    }
}
