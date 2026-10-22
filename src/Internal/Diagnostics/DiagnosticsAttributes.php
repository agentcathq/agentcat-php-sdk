<?php

declare(strict_types=1);

namespace AgentCat\Internal\Diagnostics;

use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\Env;

/**
 * The OTLP attributes of diagnostics (D48): PHP values in the Node vocabulary. Empty values are left out (TS `attr()`).
 *
 * @internal
 */
final class DiagnosticsAttributes
{
    /**
     * Without a project, `agentcat.install_id` hashes the host name and `file://` + the package root (`__DIR__` has its
     * symlinks resolved). A host function in `disable_functions` (`getmypid`, `php_uname`) leaves out only its
     * attribute; without `gethostname` the host name is empty (TS `os.hostname?.() ?? ""`).
     *
     * @param (\Closure(string): bool)|null $functionExists tests only: tells whether a host function can be called; default `function_exists()`
     *
     * @return list<array{key: string, value: array{stringValue: string}}> the resource attributes of every request
     */
    public static function resource(string $projectId, ?\Closure $functionExists = null): array
    {
        $can = $functionExists ?? function_exists(...);
        $host = $can('gethostname') ? (string) gethostname() : '';
        $uname = $can('php_uname');

        return self::list([
            $projectId !== '' ? ['agentcat.project_id', $projectId] : ['agentcat.install_id', self::installId($host, 'file://' . dirname(__DIR__, 3))],
            ['agentcat.sdk.language', 'php'],
            ['agentcat.sdk.version', SdkVersion::get()],
            ['agentcat.mcp_sdk.version', SdkVersion::installed('mcp/sdk')],
            ['process.runtime.name', 'php'],
            ['process.runtime.version', PHP_VERSION],
            ['process.pid', $can('getmypid') ? (string) getmypid() : null],
            ['os.type', self::osType(PHP_OS_FAMILY)],
            ['os.version', $uname ? php_uname('r') : null],
            ['host.arch', $uname ? self::arch(php_uname('m')) : null],
            ['deployment.environment', Env::get('APP_ENV')],
        ]);
    }

    /** @return list<array{key: string, value: array{stringValue: string}}> on every record, so they survive flattening */
    public static function record(): array
    {
        return self::list([
            ['agentcat.sdk.version', SdkVersion::get()],
            ['process.runtime.version', PHP_VERSION],
            ['agentcat.mcp_sdk.version', SdkVersion::installed('mcp/sdk')],
        ]);
    }

    /** The first 16 hex digits of a SHA-256: the host name is hashed, never sent. */
    public static function installId(string $hostname, string $installPath): string
    {
        return substr(hash('sha256', $hostname . '|' . $installPath), 0, 16);
    }

    /** Node `os.platform()` names: `PHP_OS_FAMILY` in lower case (`linux`, `darwin`), and `win32` for Windows. */
    public static function osType(string $family): string
    {
        return $family === 'Windows' ? 'win32' : strtolower($family);
    }

    /** Node `os.arch()` names for x64 and arm64 (any case); any other machine name as is. */
    public static function arch(string $machine): string
    {
        return match (strtolower($machine)) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => $machine,
        };
    }

    /**
     * @param list<array{0: string, 1: ?string}> $pairs
     *
     * @return list<array{key: string, value: array{stringValue: string}}>
     */
    private static function list(array $pairs): array
    {
        $out = [];
        foreach ($pairs as [$key, $value]) {
            if ($value !== null && $value !== '') {
                $out[] = ['key' => $key, 'value' => ['stringValue' => $value]];
            }
        }

        return $out;
    }
}
