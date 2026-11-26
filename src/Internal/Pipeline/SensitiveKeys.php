<?php

declare(strict_types=1);

namespace AgentCat\Internal\Pipeline;

use AgentCat\Internal\Support\Values;

/**
 * Replaces the values of keys that name a secret (`authorization`, `cookie`, `x-api-key`, `pageToken`, …) with
 * `[redacted]`, at any depth. A port of the shared reference implementation; `tests/Fixtures/sensitive-keys/vectors.json`
 * holds its cases.
 *
 * @internal
 */
final class SensitiveKeys
{
    public const string MASK = '[redacted]';

    // A key matches a name when its canonical form equals the name or ends with "-" + name.
    private const array EXACT = [
        'accesskey', 'cb-access-sign', 'cf-access-client-id', 'dpop', 'fastly-key',
        'jenkins-crumb', 'paypal-transmission-sig', 'plaid-verification',
        'sec-websocket-protocol', 'x-airtable-content-mac', 'x-amzn-ava-user-context',
        'x-amzn-oidc-data', 'x-firebase-appcheck', 'x-forge-oauth-system',
        'x-forge-oauth-user', 'x-honeycomb-team', 'x-insert-key', 'x-jfrog-art-api',
        'x-nf-sign', 'x-registry-config', 'x-requestdigest', 'x-sfdc-session',
        'x-trello-webhook', 'x-vault-mfa', 'x-vercel-protection-bypass', 'x-wp-nonce',
    ];

    // A key matches when its canonical form contains the word.
    private const array CONTAINS = [
        'authorization', 'secret', 'password', 'passwd', 'passphrase', 'cookie', 'signature',
        'credential', 'jwt', 'assertion', 'bearer', 'csrf', 'xsrf', 'hmac', 'saml',
        'api-key', 'apikey', 'access-key', 'private-key', 'privatekey', 'subscription-key',
        'encryption-key', 'signing-key', 'shared-key', 'sharedkey', 'master-key',
        'account-key', 'app-key', 'appkey', 'application-key', 'functions-key', 'sas-key',
        'license-key', 'consumer-key', 'client-key', 'customer-key',
    ];

    // A key matches when one hyphen-separated part of its canonical form equals the word.
    private const array SEGMENT = ['auth', 'token', 'otp'];

    // A key matches when its canonical form ends with the word.
    private const array SUFFIX = ['token'];

    // The pair rule: a map that names a key in one entry and holds its value in another.
    private const array PAIR_NAME_KEYS = ['name', 'key', 'header'];

    private const array PAIR_VALUE_KEYS = ['value', 'values'];

    // The children of a map stored under this exact key are JSON Schema property names.
    private const string SCHEMA_PROPERTIES_KEY = 'properties';

    // The nesting cap and the cycle guard of the Sanitizer: past them, the subtree becomes the mask.
    private const int MAX_DEPTH = 512;

    private static function camelSplit(string $text): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $ch = ord($text[$i]);
            if ($i > 0 && $ch >= 0x41 && $ch <= 0x5A) { // A-Z
                $prev = ord($text[$i - 1]);
                $next = $i + 1 < $length ? ord($text[$i + 1]) : 0;
                if (($prev >= 0x61 && $prev <= 0x7A) || ($prev >= 0x30 && $prev <= 0x39)) { // a-z, 0-9
                    $out .= '-';
                } elseif (($prev >= 0x41 && $prev <= 0x5A) && ($next >= 0x61 && $next <= 0x7A)) { // A-Z, a-z
                    $out .= '-';
                }
            }
            $out .= $text[$i];
        }

        return $out;
    }

    /** @return list<string> */
    private static function canonicalParts(string $text): array
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; ++$i) {
            $ch = ord($text[$i]);
            if ($ch >= 0x41 && $ch <= 0x5A) { // A-Z
                $out .= chr($ch + 32);
            } elseif (($ch >= 0x61 && $ch <= 0x7A) || ($ch >= 0x30 && $ch <= 0x39)) { // a-z, 0-9
                $out .= $text[$i];
            } else {
                $out .= '-';
            }
        }
        $parts = array_values(array_filter(explode('-', $out), static fn(string $part): bool => $part !== ''));
        while ($parts !== [] && strspn($parts[count($parts) - 1], '0123456789') === strlen($parts[count($parts) - 1])) {
            array_pop($parts);
        }

        return $parts;
    }

    /** @param list<string> $parts */
    private static function partsMatch(array $parts): bool
    {
        if ($parts === []) {
            return false;
        }
        $joined = implode('-', $parts);
        foreach (self::EXACT as $name) {
            if ($joined === $name || str_ends_with($joined, '-' . $name)) {
                return true;
            }
        }
        foreach (self::CONTAINS as $word) {
            if (str_contains($joined, $word)) {
                return true;
            }
        }
        foreach ($parts as $part) {
            if (in_array($part, self::SEGMENT, true)) {
                return true;
            }
        }
        foreach (self::SUFFIX as $word) {
            if (str_ends_with($joined, $word)) {
                return true;
            }
        }

        return false;
    }

    public static function isSensitiveKey(string $key): bool
    {
        return self::partsMatch(self::canonicalParts($key)) || self::partsMatch(self::canonicalParts(self::camelSplit($key)));
    }

    private static function canonical(string $key): string
    {
        return implode('-', self::canonicalParts($key));
    }

    /** @param array<int, true> $ancestors */
    private static function maskValue(mixed $value, bool $underProperties, array $ancestors, int $depth): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $isMap = is_array($value) ? !array_is_list($value) : Values::isObjectLike($value) && !$value instanceof \DateTimeInterface && !$value instanceof \JsonSerializable;
        if ($isMap && $underProperties) {
            return self::maskSensitiveKeys($value, false, $ancestors, $depth);
        }

        return self::MASK;
    }

    /**
     * A masked copy of `$value`; `$value` is not changed. `$underProperties` is true when `$value` is the map stored
     * under the key "properties".
     *
     * @param array<int, true> $ancestors the IDs of the objects on the path to `$value`
     */
    public static function maskSensitiveKeys(mixed $value, bool $underProperties = false, array $ancestors = [], int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::MASK;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::maskSensitiveKeys($item, false, $ancestors, $depth + 1), $value);
        }
        if (!Values::isObjectLike($value) || $value instanceof \DateTimeInterface) {
            return $value;
        }
        if (is_object($value)) {
            if (isset($ancestors[spl_object_id($value)])) {
                return self::MASK;
            }
            $ancestors[spl_object_id($value)] = true;
            if ($value instanceof \JsonSerializable) {
                return self::maskSensitiveKeys($value->jsonSerialize(), $underProperties, $ancestors, $depth + 1);
            }
        }
        $entries = Values::entries($value);
        $out = [];
        foreach ($entries as $key => $child) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $out[$key] = self::maskValue($child, $underProperties, $ancestors, $depth + 1);
            } else {
                $out[$key] = self::maskSensitiveKeys($child, $key === self::SCHEMA_PROPERTIES_KEY, $ancestors, $depth + 1);
            }
        }
        $namesASensitiveKey = false;
        foreach ($entries as $key => $child) {
            if (is_string($key) && in_array(self::canonical($key), self::PAIR_NAME_KEYS, true)) {
                if (is_string($child) && self::isSensitiveKey($child)) {
                    $namesASensitiveKey = true;
                }
            }
        }
        if ($namesASensitiveKey) {
            foreach ($entries as $key => $child) {
                if (is_string($key) && in_array(self::canonical($key), self::PAIR_VALUE_KEYS, true)) {
                    $out[$key] = self::maskValue($child, false, $ancestors, $depth + 1);
                }
            }
        }

        return is_array($value) ? $out : (object) $out;
    }
}
